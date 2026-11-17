import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useLanguage } from '../context/LanguageContext';
import LanguageSwitch from '../components/LanguageSwitch';
import PasswordInput from '../components/PasswordInput';

export default function RegisterPage() {
    const { register } = useAuth();
    const { t } = useLanguage();
    const navigate = useNavigate();
    const [form, setForm] = useState({ terms_accepted: false, name: '', email: '', location: '', password: '', password_confirmation: '' });
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    const handleChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        if (form.password !== form.password_confirmation) {
            setError(t('Passwords do not match.', 'Hindi magkatugma ang mga password.'));
            return;
        }
        try {
            const data = await register(form);
            if (data.success) {
                setSuccess(data.message || t('Account saved to database!', 'Na-save na ang account sa database!'));
                setTimeout(() => navigate('/login'), 800);
            } else {
                setError(data.message || t('Registration failed.', 'Hindi matagumpay ang pagrehistro.'));
            }
        } catch (err) {
            const errors = err.response?.data?.errors;
            if (errors) {
                const first = Object.values(errors).flat()[0];
                setError(first);
            } else {
                setError(err.response?.data?.message || t('Network error. Please try again.', 'May error sa network.'));
            }
        }
    };

    return (
        <div className="phone-frame">
            <link rel="stylesheet" href="/css/signup-terms.css" />
            <div className="screen active" id="register">
                <div className="auth-header">
                    <div className="auth-logo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" /></svg>
                    </div>
                    <h1>ORYZATIX</h1>
                    <p>{t('Create your account to get started', 'Gumawa ng account para makapagsimula')}</p>
                </div>
                <div style={{ position: 'absolute', top: 14, right: 14 }}><LanguageSwitch /></div>
                <div className="auth-card fade-in" style={{ padding: 'clamp(20px, 5.5vw, 26px)', margin: 'clamp(16px, 4vw, 20px)' }}>
                    <h2>{t('Create Account', 'Gumawa ng Account')}</h2>
                    <p className="auth-sub">{t('Join Oryzatix for smart rice farming', 'Sumali sa Oryzatix para sa smart na pagsasaka ng palay')}</p>
                    {error && <div className="error-banner show">{error}</div>}
                    {success && <div className="success-banner show">{success}</div>}
                    <form className="auth-form" onSubmit={handleSubmit} style={{ gap: 11 }}>
                        <div className="form-group">
                            <label>{t('Full Name', 'Buong Pangalan')}</label>
                            <input
                                name="name"
                                value={form.name}
                                onChange={handleChange}
                                autoComplete="off"
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label>{t('Email Address', 'Email Address')}</label>
                            <input type="email" name="email" value={form.email} onChange={handleChange} required />
                        </div>
                        <div className="form-group">
                            <label>{t('Location (optional)', 'Lokasyon (optional)')}</label>
                            <input name="location" value={form.location} onChange={handleChange} placeholder="Roxas, Oriental Mindoro" />
                        </div>
                        <div className="form-group">
                            <label>{t('Password', 'Password')}</label>
                            <PasswordInput
                                name="password"
                                value={form.password}
                                onChange={handleChange}
                                minLength={6}
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label>{t('Confirm Password', 'Kumpirmahin ang Password')}</label>
                            <PasswordInput
                                name="password_confirmation"
                                value={form.password_confirmation}
                                onChange={handleChange}
                                minLength={6}
                                required
                            />
                        </div>
                        <div className="ory-consent">
                <input type="checkbox" id="regTerms" checked={form.terms_accepted} onChange={e => setForm({ ...form, terms_accepted: e.target.checked })} required aria-labelledby="consentPrefix consentLink" />
                <span><label htmlFor="regTerms" id="consentPrefix">{t('I agree to the Oryzatix', 'Sumasang-ayon ako sa')}</label> <button type="button" id="consentLink" aria-haspopup="dialog" onClick={() => document.getElementById('signupTerms').showModal()}>{t('Terms & Conditions', 'Mga Tuntunin at Kundisyon')}</button>.</span>
              </div>
              <dialog id="signupTerms" aria-labelledby="signupTermsTitle">
                <header><h2 id="signupTermsTitle">{t('Terms & Conditions', 'Mga Tuntunin at Kundisyon')}</h2><button type="button" aria-label="Close terms" onClick={() => document.getElementById('signupTerms').close()}>×</button></header>
                <p><strong>Your account.</strong> Provide accurate account information and keep your sign-in details private.</p>
                <p><strong>Rice-leaf photos.</strong> Upload only images you have permission to share.</p>
                <p><strong>Scan results and AI replies.</strong> Use Oryzatix for rice crop monitoring. Scan results and AI replies are guidance. Consult a qualified agricultural professional before making treatment decisions.</p>
                <p><strong>Staff advisories.</strong> Staff can review saved scans and provide advisories.</p>
              </dialog>
                        <button type="submit" className="auth-btn">{t('Create Account', 'Gumawa ng Account')}</button>
                    </form>
                    <div className="auth-switch">
                        <span>{t('Already have an account? ', 'May account ka na? ')}</span>
                        <Link to="/login">{t('Sign In', 'Mag-Log In')}</Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
