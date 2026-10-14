import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useScan } from '../context/ScanContext';
import { useLanguage } from '../context/LanguageContext';
import AppLayout from '../components/AppLayout';
import BottomNav from '../components/BottomNav';
import { SUPPORTED_DISEASE_LABELS } from '../utils/diseases';
import { resolveImageUrl } from '../utils/helpers';

const RESULT_LOCALIZATION = {
    blast: {
        tagalog: {
            name: 'Rice Leaf Blast (Pumutok na Dahon / Blast ng Palay)',
            scientific: 'Sanhi ng fungal pathogen na Magnaporthe oryzae',
            symptoms: {
                mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Leaf Blast. Maagang yugto (≤25%): may maliliit na kayumangging tuldok at hugis-diamanteng sugat sa dahon. Malusog at buo pa ang karamihan ng tanim. Mag-spray agad ng Tricyclazole o Isoprothiolane bilang proteksyon.',
                moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Leaf Blast. Katamtamang yugto (26%-60%): aktibong hugis-bangkang sugat (spindle-shaped lesions) na may abong gitna at mapulang gilid na nagdurugtong. Mag-spray ng Azoxystrobin + Difenoconazole at itigil ang sobrang Urea.',
                severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Leaf Blast. Malalang yugto (>60%): malawak na pagkasunog ng mga dahon (burnt appearance), collar rot, at matinding panganib ng neck blast sa uhay. Mag-spray agad ng therapeutic systemic fungicide.'
            }
        },
        english: {
            name: 'Rice Leaf Blast',
            scientific: 'Caused by fungal pathogen Magnaporthe oryzae',
            symptoms: {
                mild: 'Rice Leaf Blast symptoms affect approximately {pct}% of the leaf area. Early blast stage (≤25%): small initial brown specks and pinhead-sized diamond spots on leaf blades with mostly green intact canopy. Apply preventive Tricyclazole or Isoprothiolane.',
                moderate: 'Rice Leaf Blast symptoms affect approximately {pct}% of the leaf area. Moderate blast stage (26%-60%): active spindle-shaped lesions with necrotic gray centers and reddish-brown margins coalescing across leaves. Spray Azoxystrobin + Difenoconazole and suspend nitrogen topdressing.',
                severe: 'Rice Leaf Blast symptoms affect approximately {pct}% of the leaf area. Severe blast stage (>60%): extensive coalesced lesions, scorched/burnt foliage appearance, collar rot, and acute danger of neck and panicle blast failure. Apply therapeutic systemic fungicide immediately.'
            }
        }
    },
    blb: {
        tagalog: {
            name: 'Bacterial Leaf Blight (BLB / Pangungulubot ng Dahon)',
            scientific: 'Sanhi ng bakteryang Xanthomonas oryzae pv. oryzae',
            symptoms: {
                mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Bacterial Leaf Blight. Maagang yugto ng impeksyon: may bahagyang paninilaw at panunuyo sa dulo at gilid ng dahon. Agarang kontrolin gamit ang Copper Hydroxide bago kumalat sa buong taniman.',
                moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Bacterial Leaf Blight. Katamtamang yugto: may kapansin-pansing kulot at mala-alon na paninilaw na bumababa sa ugat ng dahon. Mag-spray ng Streptomycin Sulfate / Zinc Thiazole at patuyuin ang bukid nang 2-3 araw.',
                severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Bacterial Leaf Blight. Malalang yugto: malawak na panunuyo, abo/puting patay na tisyu ng dahon (kresek stage) na humahadlang sa photosynthesis. Mag-apply ng emergency therapeutic bactericide at itigil ang abono ng Urea.'
            }
        },
        english: {
            name: 'Bacterial Leaf Blight (BLB)',
            scientific: 'Caused by bacteria Xanthomonas oryzae pv. oryzae',
            symptoms: {
                mild: 'Bacterial blight lesions affect approximately {pct}% of the leaf blade. Early infection stage: minor yellowing and water-soaked margins at leaf tips. Apply preventive Copper Hydroxide immediately to halt further spread.',
                moderate: 'Bacterial blight lesions affect approximately {pct}% of the leaf blade. Intermediate stage: prominent wavy water-soaked margins extending down leaf veins. Spray Streptomycin Sulfate / Zinc Thiazole and drain paddy water for 2–3 days.',
                severe: 'Bacterial blight lesions affect approximately {pct}% of the leaf blade. Advanced severe stage: extensive blighted, grayish-white necrotic tissue with severe photosynthetic disruption. Apply therapeutic bactericide and immediately suspend nitrogen fertilization.'
            }
        }
    },
    brown_spot: {
        tagalog: {
            name: 'Rice Brown Spot (Mantsang Kayumanggi)',
            scientific: 'Sanhi ng fungal pathogen na Bipolaris oryzae',
            symptoms: {
                mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Brown Spot. Maagang yugto (≤25%): maliliit na bilog na kayumangging batik sa dahon dahil sa kakulangan sa sustansya (Potassium/Zinc). Mag-abono ng Muriate of Potash (0-0-60) at mag-spray ng Mancozeb.',
                moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Brown Spot. Katamtamang yugto (26%-60%): malalaking bilog o hugis-itlog na mantsang kayumanggi na may manilaw-nilaw na paligid (chlorotic halo). Mag-spray ng Tebuconazole o Propiconazole at isagawa ang AWD patubig.',
                severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Brown Spot. Malalang yugto (>60%): malalaking patay na tisyu sa dahon at panunuyo ng uhay (pecky rice grain rot). Mag-spray ng Propiconazole + Difenoconazole tank mix at maglagay ng organikong pataba sa susunod na cropping.'
            }
        },
        english: {
            name: 'Rice Brown Spot',
            scientific: 'Caused by fungal pathogen Bipolaris oryzae',
            symptoms: {
                mild: 'Rice Brown Spot symptoms affect approximately {pct}% of the leaf area. Early brown spot stage (≤25%): scattered small circular brown specks indicating soil nutrient stress. Apply Muriate of Potash (0-0-60) and spray Mancozeb.',
                moderate: 'Rice Brown Spot symptoms affect approximately {pct}% of the leaf area. Moderate brown spot stage (26%-60%): prominent oval to circular dark brown lesions with yellow chlorotic halos. Spray Tebuconazole or Propiconazole and practice AWD water management.',
                severe: 'Rice Brown Spot symptoms affect approximately {pct}% of the leaf area. Severe brown spot stage (>60%): extensive dark brown necrotic patches, leaf withering, and grain infection leading to pecky rice. Spray Propiconazole + Difenoconazole tank mix and incorporate organic compost.'
            }
        }
    },
    tungro: {
        tagalog: {
            name: 'Rice Tungro Disease (Paninilaw at Pagkabansot)',
            scientific: 'Sanhi ng RTBV at RTSV virus (hatid ng berdeng ngusong damo / GLH)',
            symptoms: {
                mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Tungro. Maagang yugto ng impeksyon: bahagyang paninilaw sa dulo ng itaas na dahon. Kontrolin agad ang berdeng ngusong damo (Green Leafhopper) gamit ang Imidacloprid o Thiamethoxam.',
                moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Tungro. Katamtamang yugto: kapansin-pansing dilaw-kahel (yellow-orange) na kulay sa mga dahon at may senyales ng pagkabansot ng suwi. Mag-spray ng Dinotefuran o Clothianidin at magkabit ng yellow sticky traps.',
                severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Tungro. Malalang yugto: matingkad na kahel na paninilaw, matinding pagkabansot, kakaunting suwi, at hindi paglabas ng uhay. Bunutin at sunugin ang mga grabeng apektadong tumpok (rogueing) upang hindi mahawa ang buong bukid.'
            }
        },
        english: {
            name: 'Rice Tungro Disease',
            scientific: 'Caused by RTBV & RTSV viral complex (transmitted by Green Leafhopper)',
            symptoms: {
                mild: 'Rice Tungro viral symptoms affect approximately {pct}% of the leaf blade. Early viral infection stage: light yellowing restricted to leaf tips. Control green leafhopper vectors immediately with Imidacloprid or Thiamethoxam.',
                moderate: 'Rice Tungro viral symptoms affect approximately {pct}% of the leaf blade. Moderate stage: pronounced yellow-orange discoloration extending across leaf blade with visible plant stunting. Spray Dinotefuran or Clothianidin and deploy yellow sticky traps.',
                severe: 'Rice Tungro viral symptoms affect approximately {pct}% of the leaf blade. Severe viral stage: intense orange-yellow discoloration, severe stunting, compact tillers, and failure of panicle emergence. Pull out and destroy severely infected hills (rogueing) to protect the crop.'
            }
        }
    },
    sheath_blight: {
        tagalog: {
            name: 'Sheath Blight (Bingaw ng Puso ng Palay)',
            scientific: 'Sanhi ng fungal pathogen na Rhizoctonia solani',
            symptoms: {
                mild: 'Maagang sheath blight: may maliliit na pahabang mantsa sa kaluban ng dahon malapit sa tubig. Suriin ang mga kalapit na halaman at panatilihing malinis ang bukid.',
                moderate: 'Katamtamang sheath blight: lumalaki at nagdurugtong ang mga mantsa sa kaluban at ibabang bahagi ng dahon. Bawasan ang sobrang siksik na tanim at labis na nitrogen.',
                severe: 'Malalang sheath blight: kumakalat ang mga mantsa sa itaas na bahagi ng halaman at maaaring magdulot ng pagdapa. Humingi ng payo sa lokal na agricultural technician para sa angkop na pamamahala.'
            }
        },
        english: {
            name: 'Rice Sheath Blight',
            scientific: 'Caused by the fungal pathogen Rhizoctonia solani',
            symptoms: {
                mild: 'Early sheath blight: small, oblong lesions appear on lower leaf sheaths near the waterline. Check nearby plants and keep the field clean.',
                moderate: 'Moderate sheath blight: lesions enlarge and join across sheaths and lower leaves. Reduce excessive crop density and nitrogen.',
                severe: 'Severe sheath blight: lesions spread into the upper canopy and may increase lodging risk. Ask a local agricultural technician about suitable management.'
            }
        }
    },
    healthy: {
        tagalog: {
            name: 'Malusog na Dahon ng Palay',
            scientific: 'Walang natukoy na sakit o pathogen · Optimal na Kalusugan ng Pananim',
            symptoms: 'Malusog at berde ang dahon ng palay. Walang anumang fungal lesions, bacterial streaks, o viral discoloration na natukoy. Ipagpatuloy ang Good Agricultural Practices (GAP) tulad ng balanseng pataba (NPK), tamang patubig (AWD), at regular na pagmamasid sa bukid.'
        },
        english: {
            name: 'Healthy Rice Leaf',
            scientific: 'No pathogens detected · Optimal Vegetative Crop Health',
            symptoms: 'The rice leaf is healthy and vibrant green. No fungal lesions, bacterial streaks, or viral discoloration detected. Continue Good Agricultural Practices (GAP) such as balanced NPK fertilization, AWD irrigation, and regular field monitoring.'
        }
    }
};

export default function ResultsPage() {
    const { currentScan } = useScan();
    const { t, lang: contextLang } = useLanguage();
    const navigate = useNavigate();
    const scan = currentScan;
    const isRecognized = scan?.recognized !== false;
    const imageSrc = resolveImageUrl(scan?.image_url);

    const [resultLang, setResultLang] = useState(() => {
        return contextLang === 'english' ? 'english' : 'tagalog';
    });
    const [speaking, setSpeaking] = useState(false);

    useEffect(() => {
        return () => {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        };
    }, []);

    useEffect(() => {
        if (!scan) {
            navigate('/scan', { replace: true });
        }
    }, [scan, navigate]);

    if (!scan) return null;

    if (!isRecognized) {
        const supported = scan.supported_diseases || SUPPORTED_DISEASE_LABELS;
        const msg = t(scan.message || 'This disease is not in our dataset.', scan.message_tl || 'Ang sakit na ito ay wala sa aming dataset.');

        return (
            <AppLayout>
                <div className="screen active" id="results">
                    <div className="results-header">
                        <h2>{t('No Result', 'Walang Resulta')}</h2>
                        <button type="button" onClick={() => navigate('/home')}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                        </button>
                    </div>

                    {imageSrc && (
                        <div className="result-image-card fade-in" style={{ background: 'var(--neutral-100)' }}>
                            <img src={imageSrc} alt="Uploaded scan" className="result-uploaded-img" />
                        </div>
                    )}

                    <div className="error-banner show fade-in" style={{ margin: '16px var(--content-pad-x)' }}>
                        {msg}
                    </div>

                    <div style={{ padding: '0 var(--content-pad-x)', marginTop: 8 }}>
                        <h4 style={{ fontSize: 14, fontWeight: 700, marginBottom: 8 }}>
                            {t('Supported in our dataset:', 'Supported sa aming dataset:')}
                        </h4>
                        <ul style={{ margin: 0, paddingLeft: 18, fontSize: 13, color: 'var(--neutral-600)', lineHeight: 1.7 }}>
                            {supported.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </div>

                    <div className="action-buttons fade-in">
                        <button type="button" className="btn-newscan" onClick={() => navigate('/scan')} style={{ flex: 1 }}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                            <span>{t('Try Another Scan', 'Mag-scan Muli')}</span>
                        </button>
                    </div>
                    <BottomNav />
                </div>
            </AppLayout>
        );
    }

    const lower = (scan.disease || '').toLowerCase();
    let imgCls = 'disease-blast';
    if (lower.includes('blight') || lower.includes('blb') || lower.includes('brown')) imgCls = 'disease-blb';
    else if (lower.includes('healthy')) imgCls = 'disease-healthy';

    const isHealthy = scan.severity === 'healthy' || scan.disease_key === 'healthy' || lower.includes('healthy');
    const inferredDiseaseKey = isHealthy
        ? 'healthy'
        : lower.includes('sheath')
            ? 'sheath_blight'
            : lower.includes('blight') || lower.includes('blb')
                ? 'blb'
                : lower.includes('brown') || lower.includes('spot')
                    ? 'brown_spot'
                    : lower.includes('tungro')
                        ? 'tungro'
                        : lower.includes('blast')
                            ? 'blast'
                            : null;
    const diseaseKey = RESULT_LOCALIZATION[scan.disease_key] ? scan.disease_key : inferredDiseaseKey;
    const localizedData = diseaseKey ? RESULT_LOCALIZATION[diseaseKey]?.[resultLang] : null;

    const pct = scan.affected_percentage || (scan.severity === 'severe' ? '80' : (scan.severity === 'moderate' ? '45' : '15'));
    let symptomsText = '';
    if (isHealthy && typeof localizedData?.symptoms === 'string') {
        symptomsText = localizedData.symptoms;
    } else if (localizedData?.symptoms && typeof localizedData.symptoms === 'object') {
        const sevKey = scan.severity || 'moderate';
        symptomsText = (localizedData.symptoms[sevKey] || localizedData.symptoms.moderate || '').replace('{pct}', pct);
    } else {
        symptomsText = t(
            'No disease-specific guidance is available for this scan.',
            'Walang tiyak na gabay para sa sakit na ito sa scan.'
        );
    }

    const toggleSpeech = () => {
        if (!('speechSynthesis' in window)) return;
        if (speaking && window.speechSynthesis.speaking) {
            window.speechSynthesis.cancel();
            setSpeaking(false);
            return;
        }

        window.speechSynthesis.cancel();
        const textToSpeak = (isHealthy ? 'Healthy Rice Leaf' : (scan.disease || 'Leaf Blast')) + '. ' + symptomsText;
        const utter = new SpeechSynthesisUtterance(textToSpeak);
        utter.lang = resultLang === 'english' ? 'en-US' : 'fil-PH';
        utter.rate = 0.95;
        utter.onend = () => setSpeaking(false);
        utter.onerror = () => setSpeaking(false);
        setSpeaking(true);
        window.speechSynthesis.speak(utter);
    };

    return (
        <AppLayout>
            <div className="screen active" id="results">
                <div className={`result-image-card ${imgCls} fade-in`}>
                    {imageSrc ? (
                        <img src={imageSrc} alt={scan.disease || 'Uploaded scan'} className="result-uploaded-img" />
                    ) : (
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><polyline points="21 15 16 10 5 21" /></svg>
                    )}
                    <div className="result-overlay">
                        <div className="date-tag">{scan.date || (resultLang === 'tagalog' ? 'Ngayong Araw' : 'Today')}</div>
                    </div>
                </div>

                <div className={`result-diagnosis ${isHealthy ? 'safe' : 'warning'} fade-in`}>
                    <div className={`diag-icon ${isHealthy ? 'ok' : 'warn'}`}>
                        {isHealthy ? (
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20 6L9 17l-5-5" /></svg>
                        ) : (
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /></svg>
                        )}
                    </div>
                    <div className="diag-text">
                        <h3>{isHealthy ? 'Healthy Rice Leaf' : (scan.disease || 'Leaf Blast')}</h3>
                        <p>{isHealthy ? 'No pathogens detected · Optimal Vegetative Crop Health' : (scan.scientific ? <>Caused by <em>{scan.scientific}</em></> : 'Matched against a labeled reference image; severity is estimated from image colors.')}</p>
                    </div>
                </div>

                {!isHealthy && (
                    <div className="severity-meter fade-in">
                        <h4>{resultLang === 'tagalog' ? 'Lebel ng Pinsala at Apektadong Bahagi ng Dahon' : 'Severity Level & Leaf Area Affected'}</h4>
                        <div className="severity-bar-track">
                            <div className={`severity-bar-fill ${scan.severity === 'severe' ? 'severe' : scan.severity === 'moderate' ? 'moderate' : 'mild'}`} />
                        </div>
                        <div className="severity-labels">
                            <span className={`sev-label ${scan.severity === 'mild' ? 'active-sev green' : 'inactive'}`}>
                                {resultLang === 'tagalog' ? 'Bahagya (≤ 25%)' : 'Mild (≤ 25%)'}
                            </span>
                            <span className={`sev-label ${scan.severity === 'moderate' ? 'active-sev amber' : 'inactive'}`}>
                                {resultLang === 'tagalog' ? 'Katamtaman (26% – 60%)' : 'Moderate (26% – 60%)'}
                            </span>
                            <span className={`sev-label ${scan.severity === 'severe' ? 'active-sev' : 'inactive'}`}>
                                {resultLang === 'tagalog' ? 'Malala (> 60%)' : 'Severe (> 60%)'}
                            </span>
                        </div>
                    </div>
                )}

                <div className="symptoms-card fade-in">
                    <h4>{resultLang === 'tagalog' ? 'Mga Sintomas at Epekto sa Bukid' : 'Symptoms & Field Impact'}</h4>
                    <p>{symptomsText}</p>
                    <div className="symptoms-card-footer">
                        <button 
                            type="button" 
                            className={`symptoms-action-btn speak-btn ${speaking ? 'speaking' : ''}`}
                            onClick={toggleSpeech}
                            title="Read Aloud"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                            <span>{speaking ? 'Stop' : 'Read'}</span>
                        </button>
                        <button 
                            type="button" 
                            className="symptoms-action-btn translate-btn"
                            onClick={() => {
                                if ('speechSynthesis' in window) window.speechSynthesis.cancel();
                                setSpeaking(false);
                                setResultLang((prev) => (prev === 'english' ? 'tagalog' : 'english'));
                            }}
                            title="Translate Symptoms"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <span>{resultLang === 'english' ? 'Translate to Tagalog' : 'Translate to English'}</span>
                        </button>
                    </div>
                </div>

                <div className="action-buttons fade-in">
                    <button type="button" className="btn-treatment" onClick={() => navigate('/treatment')}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" /></svg>
                        <span>{resultLang === 'tagalog' ? 'Tingnan ang Gamutan' : 'View Treatment Guide'}</span>
                    </button>
                    <button type="button" className="btn-newscan" onClick={() => navigate('/scan')}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                        <span>{resultLang === 'tagalog' ? 'Bagong Scan' : 'New Scan'}</span>
                    </button>
                </div>
                <BottomNav />
            </div>
        </AppLayout>
    );
}
