import { useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useLanguage } from '../context/LanguageContext';
import { useScan } from '../context/ScanContext';
import AppLayout from '../components/AppLayout';
import api, { ensureCsrfCookie } from '../api/client';
import { getUnsupportedScan } from '../utils/diseases';

async function uploadScan(formData) {
    await ensureCsrfCookie();
    try {
        return await api.post('/rice-detector/upload', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
    } catch (err) {
        if (err.response?.status === 419) {
            await ensureCsrfCookie(true);
            return await api.post('/rice-detector/upload', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
        }
        throw err;
    }
}

export default function ScanPage() {
    const { t } = useLanguage();
    const { setCurrentScan, markScanSaved } = useScan();
    const navigate = useNavigate();
    const fileRef = useRef(null);

    const uploadAndAnalyze = async (file) => {
        const previewUrl = URL.createObjectURL(file);
        navigate('/loading');

        const formData = new FormData();
        formData.append('image', file);

        let scan = null;
        try {
            const { data } = await uploadScan(formData);
            if (data.success && data.scan) {
                scan = {
                    ...data.scan,
                    image_url: data.scan.image_url || previewUrl,
                    recognized: data.recognized !== false,
                    message: data.message,
                    message_tl: data.message_tl,
                    supported_diseases: data.supported_diseases,
                };
                if (data.saved && data.recognized !== false) {
                    markScanSaved(scan);
                }
            }
        } catch {
            scan = getUnsupportedScan(
                previewUrl,
                t('Unable to analyze this image. Please try again with a clearer rice leaf photo.', 'Hindi ma-analyze ang larawan. Subukan muli gamit ang mas malinaw na larawan ng dahon ng palay.'),
                t('Unable to analyze this image. Please try again with a clearer rice leaf photo.', 'Hindi ma-analyze ang larawan. Subukan muli gamit ang mas malinaw na larawan ng dahon ng palay.')
            );
        }

        if (!scan) {
            scan = getUnsupportedScan(previewUrl);
        }
        setCurrentScan(scan);
        setTimeout(() => navigate('/results'), 3200);
    };

    const openFile = () => fileRef.current?.click();

    const onFileChange = (e) => {
        const file = e.target.files?.[0];
        if (file) uploadAndAnalyze(file);
        e.target.value = '';
    };

    return (
        <AppLayout>
            <div className="screen active" id="scan">
                <input ref={fileRef} type="file" accept="image/*" capture="environment" style={{ display: 'none' }} onChange={onFileChange} />
                <div className="scan-top-bar">
                    <button type="button" onClick={() => navigate('/home')}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                    </button>
                    <span>{t('Capture Leaf', 'Kunin ang Larawan ng Dahon')}</span>
                    <button type="button" onClick={openFile}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                    </button>
                </div>
                <div className="camera-viewfinder">
                    <div className="corner-marker tl" /><div className="corner-marker tr" /><div className="corner-marker bl" /><div className="corner-marker br" />
                    <div className="scan-overlay">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" /><path d="M7 12h10" /></svg>
                        <p>{t('Position leaf inside frame', 'Ilagay ang dahon sa loob ng frame')}</p>
                    </div>
                </div>
                <p className="scan-tip">{t('Reference images: BLB, Leaf Blast, Brown Spot, Sheath Blight, Tungro, and Healthy leaves.', 'Mga reference image: BLB, Leaf Blast, Brown Spot, Sheath Blight, Tungro, at malusog na dahon.')}</p>
                <div className="scan-controls">
                    <button type="button" className="secondary-btn" onClick={openFile}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><polyline points="21 15 16 10 5 21" /></svg>
                    </button>
                    <button type="button" className="capture-btn" onClick={openFile} aria-label="Capture" />
                    <button type="button" className="secondary-btn" aria-label="Flash">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" /></svg>
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}
