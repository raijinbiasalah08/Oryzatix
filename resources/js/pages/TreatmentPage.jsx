import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useScan } from '../context/ScanContext';
import { useLanguage } from '../context/LanguageContext';
import AppLayout from '../components/AppLayout';
import BottomNav from '../components/BottomNav';
import { getDefaultTreatments } from '../utils/diseases';

export default function TreatmentPage() {
    const { currentScan } = useScan();
    const { t } = useLanguage();
    const navigate = useNavigate();
    const [tab, setTab] = useState('chemical');
    const scan = currentScan;

    useEffect(() => {
        if (!scan || scan.recognized === false) {
            navigate('/scan', { replace: true });
        }
    }, [scan, navigate]);

    if (!scan || scan.recognized === false) return null;

    const hasSeverityResult = ['expert_validated', 'user_assessed', 'user_selected', 'model_estimated'].includes(scan.severity_status)
        && ['mild', 'moderate', 'severe'].includes(scan.severity);
    const suppliedTreatments = scan.treatments || {};
    const hasSuppliedTreatments = (suppliedTreatments.chemical || []).length > 0 || (suppliedTreatments.organic || []).length > 0;
    const treatments = hasSuppliedTreatments
        ? suppliedTreatments
        : hasSeverityResult
            ? getDefaultTreatments(scan.disease_key || 'blast', scan.severity)
            : suppliedTreatments;

    const lower = (scan.disease || '').toLowerCase();
    let iconCls = 'blast-bg';
    if (lower.includes('healthy')) { iconCls = 'healthy-bg'; }
    else if (lower.includes('blight') || lower.includes('blb') || lower.includes('brown')) { iconCls = 'blb-bg'; }

    const renderItems = (items) => (items || []).map((it, i) => (
        <div key={i} className="treatment-item fade-in">
            <h4>{it.name}</h4>
            <p>{it.desc}</p>
            <span className={`treat-tag ${(it.tag_class || 'cultural').toLowerCase()}`}>{it.tag || 'Cultural'}</span>
        </div>
    ));

    return (
        <AppLayout>
            <div className="screen active" id="treatment">
                <div className="treat-header">
                    <button type="button" onClick={() => navigate(-1)}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                    </button>
                    <h2>{t('Treatment Guide', 'Gabay sa Gamutan')}</h2>
                </div>
                <div className="treat-disease-card fade-in">
                    <div className={`treat-disease-icon ${iconCls}`}>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22v-9"/><path d="M12 13C8 13 4 9 4 4c5 0 9 4 9 9z"/><path d="M12 8c2-3 5-4 8-4 0 5-4 9-8 9"/></svg>
                    </div>
                    <div className="treat-disease-info">
                        <h3>{scan.disease}</h3>
                        <p>{(scan.scientific || scan.disease) + ' · Severity: ' + (scan.severity || 'severe')}</p>
                    </div>
                </div>
                <div className="treatment-tabs">
                    <button type="button" className={tab === 'chemical' ? 'active' : ''} onClick={() => setTab('chemical')}>{t('Chemical', 'Kemikal')}</button>
                    <button type="button" className={tab === 'organic' ? 'active' : ''} onClick={() => setTab('organic')}>{t('Organic / Cultural', 'Organiko / Kultural')}</button>
                </div>
                <div className="treatment-list">{tab === 'chemical' ? renderItems(treatments.chemical) : renderItems(treatments.organic)}</div>
                <BottomNav />
            </div>
        </AppLayout>
    );
}
