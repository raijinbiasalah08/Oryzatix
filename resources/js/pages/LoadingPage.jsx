import { useEffect, useState } from 'react';
import { useLanguage } from '../context/LanguageContext';
import AppLayout from '../components/AppLayout';

const STEPS = ['step1', 'step2', 'step3', 'step4'];

export default function LoadingPage() {
    const { t } = useLanguage();
    const [doneSteps, setDoneSteps] = useState([]);

    useEffect(() => {
        STEPS.forEach((step, i) => {
            setTimeout(() => setDoneSteps((prev) => [...prev, step]), 800 * (i + 1));
        });
    }, []);

    const labels = [
        t('Reading uploaded image', 'Binabasa ang na-upload na larawan'),
        t('Checking exact image match', 'Sinusuri ang eksaktong tugma ng larawan'),
        t('Loading stored severity', 'Kinukuha ang nakatalang antas ng pinsala'),
        t('Preparing recommendations', 'Inihahanda ang mga rekomendasyon'),
    ];

    return (
        <AppLayout>
            <div className="screen active" id="loading">
                <div className="loading-ring" />
                <h2>{t('Checking Reference Match', 'Sinusuri ang Reference')}</h2>
                <p>{t('Only an exact match to a labeled rice-leaf image receives a result.', 'Resulta lamang ang ibinibigay kapag eksaktong tumugma sa may label na larawan ng dahon ng palay.')}</p>
                <div className="loading-steps">
                    {STEPS.map((step, i) => (
                        <div key={step} className={`loading-step ${doneSteps.includes(step) ? 'done' : ''}`} id={step}>
                            <div className="step-check">
                                {doneSteps.includes(step) && (
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12" /></svg>
                                )}
                            </div>
                            <span>{labels[i]}</span>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
