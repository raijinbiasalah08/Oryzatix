import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import { useLanguage } from '../context/LanguageContext';
import AppLayout from '../components/AppLayout';
import { detectLanguage } from '../utils/helpers';

function formatAiText(text) {
    if (!text) return null;
    const lines = text.split(/\r?\n/);
    return lines.map((line, idx) => {
        const trimmed = line.trim();
        if (!trimmed) return <div key={idx} style={{ height: '6px' }} />;
        const isBullet = /^[\*\-•]\s+/.test(trimmed);
        const isNumber = /^\d+[\.\)]\s+/.test(trimmed);
        const cleanText = trimmed.replace(/^([\*\-•]|\d+[\.\)])\s+/, '');
        
        // Simple bold parser
        const parts = cleanText.split(/(\*\*.*?\*\*)/g).map((part, pIdx) => {
            if (part.startsWith('**') && part.endsWith('**')) {
                return <strong key={pIdx}>{part.slice(2, -2)}</strong>;
            }
            return part;
        });

        if (isBullet || isNumber) {
            return (
                <div key={idx} style={{ display: 'flex', gap: '8px', margin: '4px 0 4px 8px' }}>
                    <span style={{ color: 'var(--brand-green, #16a34a)', fontWeight: 'bold' }}>{isBullet ? '•' : trimmed.match(/^\d+[\.\)]/)[0]}</span>
                    <div>{parts}</div>
                </div>
            );
        }
        return <p key={idx} style={{ margin: '0 0 6px 0', lineHeight: 1.6 }}>{parts}</p>;
    });
}

export default function ConsultationPage() {
    const { t, lang } = useLanguage();
    const navigate = useNavigate();
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [recording, setRecording] = useState(false);
    const [isMuted, setIsMuted] = useState(() => {
        return typeof window !== 'undefined' && localStorage.getItem('oryzatix_ai_voice_muted') === 'true';
    });
    const [speakingIndex, setSpeakingIndex] = useState(null);
    const recognitionRef = useRef(null);
    const containerRef = useRef(null);

    // Stop speech synthesis on component unmount / navigate away
    useEffect(() => {
        return () => {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        };
    }, []);

    useEffect(() => {
        api.get('/consultation/messages')
            .then(({ data }) => {
                if (data.success && data.messages?.length) setMessages(data.messages);
            })
            .catch(() => {});
    }, []);

    useEffect(() => {
        if (containerRef.current) containerRef.current.scrollTop = containerRef.current.scrollHeight;
    }, [messages]);

    const stopSpeech = () => {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
        setSpeakingIndex(null);
    };

    const toggleMute = () => {
        const next = !isMuted;
        setIsMuted(next);
        localStorage.setItem('oryzatix_ai_voice_muted', next ? 'true' : 'false');
        if (next) stopSpeech();
    };

    const speakText = (text, language, msgIdx = null) => {
        if (!('speechSynthesis' in window)) return;
        
        if (speakingIndex === msgIdx && window.speechSynthesis.speaking) {
            stopSpeech();
            return;
        }

        stopSpeech();
        if (isMuted && msgIdx === null) return;

        const clean = text.replace(/\*\*/g, '').replace(/[\*\-•]/g, '').trim();
        if (!clean) return;

        const utter = new SpeechSynthesisUtterance(clean);
        utter.lang = language === 'english' ? 'en-US' : 'fil-PH';
        utter.rate = 0.95;
        utter.pitch = 1.0;

        if (msgIdx !== null) setSpeakingIndex(msgIdx);

        utter.onend = () => setSpeakingIndex(null);
        utter.onerror = () => setSpeakingIndex(null);

        window.speechSynthesis.speak(utter);
    };

    const sendMessage = async (text) => {
        const msg = (text || input).trim();
        if (!msg) return;
        const language = detectLanguage(msg);
        setMessages((prev) => [...prev, { role: 'user', content: msg, language }]);
        setInput('');

        try {
            const { data } = await api.post('/consultation/send', { message: msg, language });
            if (!data?.success || typeof data.ai_response !== 'string' || !data.ai_response.trim()) {
                throw new Error('Consultation service returned no answer');
            }
            const reply = data.ai_response.trim();
            const replyLang = data.language || language;
            setMessages((prev) => [...prev, { role: 'ai', content: reply, language: replyLang }]);
            if (!isMuted) {
                speakText(reply, replyLang);
            }
        } catch {
            const fallback = language === 'english'
                ? 'I could not reach the rice advisory service, so I do not have an answer yet. Please try again shortly. For time-sensitive crop or chemical decisions, contact your Municipal Agriculture Office.'
                : 'Hindi ko makontak ang serbisyo ng payo sa palay kaya wala pa akong sagot. Subukan muli mamaya. Para sa agarang desisyon tungkol sa pananim o kemikal, kumonsulta sa Municipal Agriculture Office.';
            setMessages((prev) => [...prev, { role: 'ai', content: fallback, language }]);
        }
    };

    const toggleVoice = () => {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            alert(t('Speech recognition not supported. Use Chrome or Edge.', 'Hindi suportado ang speech recognition. Gamitin ang Chrome o Edge.'));
            return;
        }
        if (!recording) {
            const rec = new SpeechRecognition();
            rec.lang = lang === 'tagalog' ? 'fil-PH' : 'en-US';
            rec.onresult = (e) => sendMessage(e.results[0][0].transcript);
            rec.onend = () => setRecording(false);
            rec.onerror = () => setRecording(false);
            recognitionRef.current = rec;
            rec.start();
            setRecording(true);
        } else {
            recognitionRef.current?.stop();
            setRecording(false);
        }
    };

    return (
        <AppLayout>
            <div className="screen active" id="consultation">
                <div className="chat-header">
                    <button type="button" className="back-btn" onClick={() => navigate('/home')}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                    </button>
                    <div className="ai-avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" /></svg>
                    </div>
                    <div className="chat-header-info">
                        <h3>{t('Rice AI Assistant', 'Rice AI Assistant')}</h3>
                        <p>{t('Online · Tagalog / English', 'Online · Tagalog / English')}</p>
                    </div>
                    <button 
                        type="button" 
                        className={`chat-mute-btn ${isMuted ? 'muted' : ''}`}
                        onClick={toggleMute}
                        title={isMuted ? 'Unmute AI Voice' : 'Mute AI Voice'}
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                            {isMuted ? (
                                <>
                                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
                                    <line x1="23" y1="9" x2="17" y2="15" />
                                    <line x1="17" y1="9" x2="23" y2="15" />
                                </>
                            ) : (
                                <>
                                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
                                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07" />
                                </>
                            )}
                        </svg>
                        <span>{isMuted ? 'Voice: Muted' : 'Voice: On'}</span>
                    </button>
                </div>
                <div className="chat-messages" ref={containerRef}>
                    {messages.length === 0 && (
                        <div className="chat-bubble ai">
                            <div className="chat-text-content">
                                <p>{t("Hello! I'm your Rice AI Assistant. Ask me about rice symptoms, crop care, or irrigation.", "Kumusta! Ako ang inyong Rice AI Assistant. Pwede ninyo akong tanungin tungkol sa mga sintomas ng palay, pangangalaga ng pananim, o patubig.")}</p>
                            </div>
                            <div className="chat-bubble-footer">
                                <button 
                                    className={`audio-speaker-btn ${speakingIndex === 'init' ? 'speaking' : ''}`}
                                    onClick={() => speakText("Kumusta! Ako ang inyong Rice AI Assistant. Pwede ninyo akong tanungin tungkol sa mga sakit ng palay.", 'tagalog', 'init')}
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                                    <span>{speakingIndex === 'init' ? 'Stop' : 'Read'}</span>
                                </button>
                            </div>
                        </div>
                    )}
                    {messages.map((m, i) => (
                        <div key={i} className={`chat-bubble ${m.role === 'user' ? 'user' : 'ai'} fade-in`}>
                            {m.role === 'user' ? (
                                <p>{m.content}</p>
                            ) : (
                                <>
                                    <div className="chat-text-content">
                                        {formatAiText(m.content)}
                                    </div>
                                    <div className="chat-bubble-footer">
                                        <button 
                                            className={`audio-speaker-btn ${speakingIndex === i ? 'speaking' : ''}`}
                                            onClick={() => speakText(m.content, m.language || 'tagalog', i)}
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                                            <span>{speakingIndex === i ? 'Stop' : 'Read'}</span>
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    ))}
                </div>
                <div className="chat-input-bar">
                    <button type="button" className={`voice-btn ${recording ? 'recording' : ''}`} onClick={toggleVoice}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" /><path d="M19 10v2a7 7 0 0 1-14 0v-2" /></svg>
                    </button>
                    <input
                        type="text"
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && sendMessage()}
                        placeholder={t('Ask about rice diseases...', 'Magtanong tungkol sa sakit ng palay...')}
                    />
                    <button type="button" className="send-btn" onClick={() => sendMessage()}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" /></svg>
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}
