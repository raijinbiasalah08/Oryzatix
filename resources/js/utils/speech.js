import api from '../api/client';

let activeSpeechRun = 0;
let activeCloudAudio = null;
let activeCloudObjectUrl = null;
let activeCloudRequest = null;
let activeCloudCancelPlayback = null;

function getSpeechLocale(language) {
    return /^(tl|fil|tagalog|filipino)/i.test(String(language || '')) ? 'fil-PH' : 'en-PH';
}

function cleanSpeechText(value) {
    return String(value || '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/^\s{0,3}#{1,6}\s*/gm, '')
        .replace(/^\s*(?:[-*•]|\d+[.)])\s+/gm, '')
        .replace(/\*\*(.*?)\*\*/g, '$1')
        .replace(/__(.*?)__/g, '$1')
        .replace(/\*([^*\n]+)\*/g, '$1')
        .replace(/`([^`]+)`/g, '$1')
        .replace(/https?:\/\/\S+/gi, '')
        .replace(/\r?\n+/g, '. ')
        .replace(/\s+/g, ' ')
        .replace(/([.!?])(?:\s*[.!?])+/g, '$1')
        .trim();
}

function chooseSpeechVoice(voices, locale) {
    const isFilipino = locale.toLowerCase().startsWith('fil-');
    const candidates = voices.filter((voice) => {
        const voiceLocale = String(voice.lang || '').toLowerCase();
        return isFilipino
            ? /^(fil|tl)(-|$)/i.test(voiceLocale)
            : /^en(-|$)/i.test(voiceLocale);
    });

    const score = (voice) => {
        const voiceLocale = String(voice.lang || '').toLowerCase();
        const voiceName = String(voice.name || '');
        let value = 0;
        if (voiceLocale === locale.toLowerCase()) value += 100;
        else if (isFilipino && /^(fil|tl)-ph$/i.test(voiceLocale)) value += 90;
        else if (!isFilipino && /^en-ph$/i.test(voiceLocale)) value += 80;
        if (isFilipino && /filipino|tagalog/i.test(voiceName)) value += 35;
        if (/natural|neural|enhanced|premium/i.test(voiceName)) value += 25;
        if (/online|google|microsoft/i.test(voiceName)) value += 4;
        return value;
    };

    return candidates.sort((left, right) => score(right) - score(left))[0] || null;
}

function splitSpeechText(text, limit = 230) {
    const sentences = text.match(/[^.!?;:]+[.!?;:]+|[^.!?;:]+$/g) || [text];
    const chunks = [];
    let current = '';

    sentences.map((sentence) => sentence.trim()).filter(Boolean).forEach((sentence) => {
        sentence.split(/\s+/).forEach((word) => {
            const pieces = word.length > limit
                ? word.match(new RegExp(`.{1,${limit}}`, 'g')) || [word]
                : [word];
            pieces.forEach((piece) => {
                const next = current ? `${current} ${piece}` : piece;
                if (next.length > limit && current) {
                    chunks.push(current);
                    current = piece;
                } else {
                    current = next;
                }
            });
        });
    });

    if (current) chunks.push(current);
    return chunks;
}

function releaseCloudAudio(audio, objectUrl) {
    if (audio) {
        audio.onplay = null;
        audio.onended = null;
        audio.onerror = null;
        if (activeCloudAudio === audio) {
            activeCloudAudio = null;
            audio.pause();
            audio.removeAttribute('src');
            audio.load();
        }
    }
    if (objectUrl && activeCloudObjectUrl === objectUrl) {
        activeCloudObjectUrl = null;
        URL.revokeObjectURL(objectUrl);
    }
}

async function playCloudSpeech(text, locale, run, onStart) {
    const chunks = splitSpeechText(text, 1700);
    let completedChunks = 0;

    for (const chunk of chunks) {
        if (run !== activeSpeechRun) return { cancelled: true, completedChunks };

        const controller = new AbortController();
        activeCloudRequest = controller;
        let response;
        try {
            response = await api.post('/consultation/narrate', {
                text: chunk,
                language: locale === 'fil-PH' ? 'tagalog' : 'english',
            }, {
                responseType: 'blob',
                timeout: 45000,
                signal: controller.signal,
                headers: { Accept: 'audio/mpeg' },
            });
        } catch (_) {
            if (run !== activeSpeechRun) return { cancelled: true, completedChunks };
            return { completedChunks };
        } finally {
            if (activeCloudRequest === controller) activeCloudRequest = null;
        }

        if (run !== activeSpeechRun) return { cancelled: true, completedChunks };
        const blob = response?.data;
        if (!(blob instanceof Blob) || !blob.size || !blob.type.toLowerCase().startsWith('audio/')) {
            return { completedChunks };
        }

        const objectUrl = URL.createObjectURL(blob);
        const audio = new Audio(objectUrl);
        activeCloudObjectUrl = objectUrl;
        activeCloudAudio = audio;
        audio.volume = 1;

        let cancelPlayback = null;
        try {
            await new Promise((resolve, reject) => {
                cancelPlayback = () => {
                    const error = new Error('Narration playback stopped.');
                    error.name = 'AbortError';
                    reject(error);
                };
                activeCloudCancelPlayback = cancelPlayback;
                audio.onplay = onStart;
                audio.onended = resolve;
                audio.onerror = () => reject(new Error('Generated narration could not be played.'));
                audio.play().catch(reject);
            });
        } catch (_) {
            releaseCloudAudio(audio, objectUrl);
            if (run !== activeSpeechRun) return { cancelled: true, completedChunks };
            return { completedChunks };
        } finally {
            if (activeCloudCancelPlayback === cancelPlayback) activeCloudCancelPlayback = null;
        }

        releaseCloudAudio(audio, objectUrl);
        if (run !== activeSpeechRun) return { cancelled: true, completedChunks };
        completedChunks += 1;
    }

    return { complete: true, completedChunks };
}

function playDeviceSpeech(text, locale, voice, run, onStart, onEnd, onError) {
    const chunks = splitSpeechText(text);
    let index = 0;

    const speakNext = () => {
        if (run !== activeSpeechRun) return;
        if (index >= chunks.length) {
            onEnd?.();
            return;
        }

        const utterance = new SpeechSynthesisUtterance(chunks[index]);
        utterance.lang = voice?.lang || locale;
        if (voice) utterance.voice = voice;
        utterance.rate = locale.startsWith('fil-') ? 0.95 : 0.97;
        utterance.pitch = 1;
        utterance.volume = 1;
        utterance.onstart = () => {
            if (run === activeSpeechRun) onStart();
        };
        utterance.onend = () => {
            if (run !== activeSpeechRun) return;
            index += 1;
            speakNext();
        };
        utterance.onerror = (event) => {
            if (run !== activeSpeechRun || event.error === 'canceled' || event.error === 'interrupted') return;
            onError?.(new Error(`Speech playback failed: ${event.error || 'unknown error'}`));
        };

        window.speechSynthesis.speak(utterance);
    };

    speakNext();
}

function waitForSpeechVoices(synthesis) {
    const currentVoices = synthesis.getVoices();
    if (currentVoices.length) return Promise.resolve(currentVoices);

    return new Promise((resolve) => {
        let settled = false;
        const finish = () => {
            if (settled) return;
            settled = true;
            synthesis.removeEventListener?.('voiceschanged', finish);
            resolve(synthesis.getVoices());
        };
        synthesis.addEventListener?.('voiceschanged', finish, { once: true });
        window.setTimeout(finish, 900);
    });
}

export function cancelNaturalSpeech() {
    activeSpeechRun += 1;
    if (typeof window !== 'undefined' && 'speechSynthesis' in window) {
        window.speechSynthesis.cancel();
    }
    activeCloudRequest?.abort();
    activeCloudRequest = null;
    activeCloudCancelPlayback?.();
    activeCloudCancelPlayback = null;
    releaseCloudAudio(activeCloudAudio, activeCloudObjectUrl);
}

export async function speakNaturally(value, language, { onStart, onEnd, onError } = {}) {
    if (typeof window === 'undefined') {
        onError?.(new Error('Speech playback is not available in this environment.'));
        return;
    }

    const text = cleanSpeechText(value);
    if (!text) {
        onEnd?.();
        return;
    }

    const canUseDeviceVoice = 'speechSynthesis' in window && typeof SpeechSynthesisUtterance !== 'undefined';
    const synthesis = canUseDeviceVoice ? window.speechSynthesis : null;
    cancelNaturalSpeech();
    const run = ++activeSpeechRun;

    try {
        const locale = getSpeechLocale(language);
        const cloudAvailable = typeof navigator === 'undefined' || navigator.onLine !== false;
        const voicesReady = canUseDeviceVoice ? waitForSpeechVoices(synthesis) : Promise.resolve([]);
        let didStart = false;

        const signalStart = () => {
            if (!didStart) {
                didStart = true;
                onStart?.();
            }
        };

        if (cloudAvailable) {
            const cloudResult = await playCloudSpeech(text, locale, run, signalStart);
            if (run !== activeSpeechRun || cloudResult.cancelled) return;
            if (cloudResult.complete) {
                onEnd?.();
                return;
            }

            if (!canUseDeviceVoice) {
                onError?.(new Error('Online narration is unavailable and this device has no speech voice.'));
                return;
            }
            const voice = chooseSpeechVoice(await voicesReady, locale);
            if (run !== activeSpeechRun) return;
            const deviceFallbackText = splitSpeechText(text, 1700)
                .slice(cloudResult.completedChunks || 0)
                .join(' ');
            playDeviceSpeech(deviceFallbackText || text, locale, voice, run, signalStart, onEnd, onError);
            return;
        }

        if (!canUseDeviceVoice) {
            onError?.(new Error('Speech synthesis is not available on this device.'));
            return;
        }
        const voice = chooseSpeechVoice(await voicesReady, locale);
        if (run !== activeSpeechRun) return;
        playDeviceSpeech(text, locale, voice, run, signalStart, onEnd, onError);
    } catch (error) {
        if (run === activeSpeechRun) onError?.(error);
    }
}
