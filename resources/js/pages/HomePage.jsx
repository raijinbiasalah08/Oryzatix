import { useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useLanguage } from '../context/LanguageContext';
import { useScan } from '../context/ScanContext';
import AppLayout from '../components/AppLayout';
import BottomNav from '../components/BottomNav';
import LanguageSwitch from '../components/LanguageSwitch';
import { getThumbClass, getSeverityBadgeClass, resolveImageUrl } from '../utils/helpers';

export default function HomePage() {
    const { user } = useAuth();
    const { t } = useLanguage();
    const { recentScans, loadRecentScans, recentScansTick, setCurrentScan } = useScan();
    const navigate = useNavigate();
    const location = useLocation();

    useEffect(() => {
        loadRecentScans();
    }, [loadRecentScans, location.key, recentScansTick]);

    const openRecentScan = (scan) => {
        setCurrentScan({
            id: scan.id,
            disease: scan.disease,
            scientific: scan.scientific,
            confidence: scan.confidence,
            severity: scan.severity,
            severity_status: scan.severity_status,
            severity_model_version: scan.severity_model_version,
            severity_class: scan.severity_class,
            image_url: scan.image_url,
            treatments: scan.treatments,
            date: scan.date,
            time: scan.time,
        });
        navigate('/results');
    };

    return (
        <AppLayout>
            <div className="screen active" id="home">
                <div className="home-header">
                    <div className="greeting">{t('Good day,', 'Magandang araw,')}</div>
                    <div className="user-name">{user?.name || 'User'}</div>
                    <div className="header-actions">
                        <div style={{ marginRight: 10, alignSelf: 'center' }}><LanguageSwitch compact /></div>
                    </div>
                </div>

                <div className="quick-scan-card fade-in">
                    <h3>{t('Detect Rice Disease', 'Tukuyin ang Sakit sa Palay')}</h3>
                    <p>{t('Capture or upload a photo of your rice plant leaf for instant AI-powered diagnosis.', 'Kumuha o mag-upload ng larawan ng dahon ng palay para sa agarang AI diagnosis.')}</p>
                    <button type="button" className="scan-action-btn" onClick={() => navigate('/scan')}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                        <span>{t('Scan Now', 'I-scan Ngayon')}</span>
                    </button>
                </div>

                <div className="section-header" style={{ marginTop: 16 }}>
                    <h3>{t('Recent Scans', 'Kamakailang mga Scan')}</h3>
                </div>

                <div className={`recent-scans-list${recentScans.length > 4 ? ' has-scroll' : ''}`} id="homeRecentScansContainer">
                    {recentScans.length === 0 ? (
                        <p style={{ padding: '12px 20px', color: 'var(--neutral-500)', fontSize: 13 }}>{t('No scans yet. Start your first scan!', 'Wala pang scan. Simulan ang unang scan!')}</p>
                    ) : (
                        recentScans.map((s, i) => (
                            <div key={s.id || i} className="recent-scan-card fade-in" onClick={() => openRecentScan(s)} role="button" tabIndex={0}>
                                <div className={`scan-thumb ${getThumbClass(s.disease)}`} style={s.image_url ? { padding: 0, overflow: 'hidden' } : undefined}>
                                    {s.image_url ? (
                                        <img src={resolveImageUrl(s.image_url)} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                    ) : (
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="24" height="24"><path d="M12 2v4M7 12h10" /></svg>
                                    )}
                                </div>
                                <div className="scan-info">
                                    <div className="disease-name">{s.disease}</div>
                                    <div className="scan-meta">{s.time}</div>
                                </div>
                                <span className={`severity-badge ${getSeverityBadgeClass(s.severity)}`}>{s.severity}{s.severity_status === 'model_estimated' ? ' (experimental)' : ''}</span>
                            </div>
                        ))
                    )}
                </div>

                <div className="section-header" style={{ marginTop: 20 }}>
                    <h3>{t('Features', 'Mga Tampok')}</h3>
                </div>
                <div className="feature-grid">
                    {[
                        { path: '/scan', icon: 'green', title: t('Disease Scan', 'Pag-scan ng Sakit'), sub: t('AI-powered detection', 'Pagtukoy gamit ang AI') },
                        { path: '/history', icon: 'blue', title: t('History', 'Kasaysayan'), sub: t('Past detection logs', 'Nakaraang mga detection') },
                        { path: '/consultation', icon: 'amber', title: t('AI Consult', 'AI Konsulta'), sub: t('Voice & text chat', 'Voice at text chat') },
                        { path: '/treatment', icon: 'red', title: t('Treatments', 'Mga Gamutan'), sub: t('Expert recommendations', 'Rekomendasyon ng eksperto') },
                    ].map((f) => (
                        <div key={f.path} className="feature-card" onClick={() => navigate(f.path)} role="button" tabIndex={0}>
                            <div className={`fc-icon ${f.icon}`}>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10" /></svg>
                            </div>
                            <h4>{f.title}</h4>
                            <p>{f.sub}</p>
                        </div>
                    ))}
                </div>
                <BottomNav active="home" />
            </div>
        </AppLayout>
    );
}
