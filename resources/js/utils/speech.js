let activeSpeechRun = 0;

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
}

export async function speakNaturally(value, language, { onStart, onEnd, onError } = {}) {
    if (typeof window === 'undefined' || !('speechSynthesis' in window) || typeof SpeechSynthesisUtterance === 'undefined') {
        onError?.(new Error('Speech synthesis is not available on this device.'));
        return;
    }

    const text = cleanSpeechText(value);
    if (!text) {
        onEnd?.();
        return;
    }

    const synthesis = window.speechSynthesis;
    const run = ++activeSpeechRun;
    synthesis.cancel();

    try {
        const locale = getSpeechLocale(language);
        const voices = await waitForSpeechVoices(synthesis);
        if (run !== activeSpeechRun) return;

        const voice = chooseSpeechVoice(voices, locale);
        const chunks = splitSpeechText(text);
        let index = 0;
        let didStart = false;

        const speakNext = () => {
            if (run !== activeSpeechRun) return;
            if (index >= chunks.length) {
                onEnd?.();
                return;
            }

            const utterance = new SpeechSynthesisUtterance(chunks[index]);
            utterance.lang = voice?.lang || locale;
            if (voice) utterance.voice = voice;
            utterance.rate = 0.91;
            utterance.pitch = 1;
            utterance.volume = 1;
            utterance.onstart = () => {
                if (run !== activeSpeechRun) return;
                if (!didStart) {
                    didStart = true;
                    onStart?.();
                }
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

            synthesis.speak(utterance);
        };

        speakNext();
    } catch (error) {
        if (run === activeSpeechRun) onError?.(error);
    }
}
