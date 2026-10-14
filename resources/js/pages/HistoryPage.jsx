import { useEffect, useState, useCallback, useMemo } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import api from '../api/client';
import { useLanguage } from '../context/LanguageContext';
import { useScan } from '../context/ScanContext';
import AppLayout from '../components/AppLayout';
import BottomNav from '../components/BottomNav';
import { resolveImageUrl } from '../utils/helpers';

export default function HistoryPage() {
    const { t } = useLanguage();
    const { setCurrentScan, recentScansTick } = useScan();
    const navigate = useNavigate();
    const location = useLocation();
    const [stats, setStats] = useState({ healthy: 0, mild: 0, moderate: 0, severe: 0, total: 0 });
    const [allScans, setAllScans] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [activeFilter, setActiveFilter] = useState('all');
    const [currentPage, setCurrentPage] = useState(1);
    const pageSize = 10;

    const loadHistory = useCallback(() => {
        api.get('/rice-detector/history')
            .then(({ data }) => {
                if (!data.success) return;
                setStats(data.stats || { healthy: 0, mild: 0, moderate: 0, severe: 0, total: 0 });
                const flatList = [];
                const groups = data.scans || {};
                Object.keys(groups).forEach((dateLabel) => {
                    (groups[dateLabel] || []).forEach((scan) => {
                        flatList.push({ ...scan, dateLabel });
                    });
                });
                setAllScans(flatList);
            })
            .catch(() => {});
    }, []);

    useEffect(() => {
        loadHistory();
    }, [loadHistory, location.key, recentScansTick]);

    const openScan = (scan) => {
        setCurrentScan({
            id: scan.id,
            disease: scan.disease,
            disease_key: scan.disease_key,
            scientific: scan.scientific,
            confidence: scan.confidence,
            severity: scan.severity,
            severity_class: scan.severity_class,
            image_url: scan.image_url,
            treatments: scan.treatments,
            date: scan.date,
        });
        navigate('/results');
    };

    const deleteScan = (id) => {
        if (!confirm(t('Delete this scan record?', 'Burahin ang scan record na ito?'))) return;
        api.delete(`/rice-detector/scan/${id}`).then(() => loadHistory()).catch(() => loadHistory());
    };

    const filteredScans = useMemo(() => {
        let result = allScans;
        const q = searchQuery.toLowerCase().trim();

        if (activeFilter !== 'all') {
            result = result.filter((s) => {
                const sev = (s.severity || '').toLowerCase();
                const dis = (s.disease || '').toLowerCase();
                if (activeFilter === 'healthy') return sev === 'healthy' || dis.includes('healthy');
                if (activeFilter === 'mild') return sev === 'mild';
                if (activeFilter === 'moderate') return sev === 'moderate';
                if (activeFilter === 'severe') return sev === 'severe';
                if (activeFilter === 'blast') return dis.includes('blast');
                if (activeFilter === 'blb') return dis.includes('blight') || dis.includes('blb');
                if (activeFilter === 'brown_spot') return dis.includes('brown') || dis.includes('spot');
                if (activeFilter === 'sheath_blight') return dis.includes('sheath');
                if (activeFilter === 'tungro') return dis.includes('tungro');
                return true;
            });
        }

        if (q) {
            result = result.filter((s) => {
                const dis = (s.disease || '').toLowerCase();
                const sci = (s.scientific || '').toLowerCase();
                const date = (s.date || s.dateLabel || '').toLowerCase();
                const sev = (s.severity || '').toLowerCase();
                return dis.includes(q) || sci.includes(q) || date.includes(q) || sev.includes(q);
            });
        }

        return result;
    }, [allScans, searchQuery, activeFilter]);

    const totalPages = Math.ceil(filteredScans.length / pageSize) || 1;
    const startIndex = (currentPage - 1) * pageSize;
    const endIndex = Math.min(startIndex + pageSize, filteredScans.length);
    const paginatedItems = filteredScans.slice(startIndex, endIndex);

    const handleFilterChange = (filter) => {
        setActiveFilter(filter);
        setCurrentPage(1);
    };

    const renderSeverityBadge = (sev) => {
        if (sev === 'healthy') {
            return <span className="sev-badge healthy">{t('Healthy Leaf', 'Malusog')}</span>;
        }
        if (sev === 'mild') {
            return <span className="sev-badge mild">{t('Mild (≤ 25%)', 'Banayad (≤ 25%)')}</span>;
        }
        if (sev === 'moderate') {
            return <span className="sev-badge moderate">{t('Moderate (26% – 60%)', 'Katamtaman (26% – 60%)')}</span>;
        }
        return <span className="sev-badge severe">{t('Severe (> 60%)', 'Malubha (> 60%)')}</span>;
    };

    const renderPaginationNumbers = () => {
        const pages = [];
        const maxVisible = 5;
        let start = Math.max(1, currentPage - 2);
        let end = Math.min(totalPages, start + maxVisible - 1);
        if (end - start < maxVisible - 1) {
            start = Math.max(1, end - maxVisible + 1);
        }

        if (start > 1) {
            pages.push(
                <button key={1} type="button" className="page-btn" onClick={() => setCurrentPage(1)}>1</button>
            );
            if (start > 2) pages.push(<span key="dots1" className="page-ellipsis">...</span>);
        }

        for (let p = start; p <= end; p++) {
            pages.push(
                <button key={p} type="button" className={`page-btn ${p === currentPage ? 'active' : ''}`} onClick={() => setCurrentPage(p)}>
                    {p}
                </button>
            );
        }

        if (end < totalPages) {
            if (end < totalPages - 1) pages.push(<span key="dots2" className="page-ellipsis">...</span>);
            pages.push(
                <button key={totalPages} type="button" className="page-btn" onClick={() => setCurrentPage(totalPages)}>{totalPages}</button>
            );
        }

        return pages;
    };

    return (
        <AppLayout>
            <div className="screen active" id="history">
                <div className="history-header">
                    <h2>{t('Scan History', 'Kasaysayan ng mga Scan')}</h2>
                    <span style={{ fontSize: 12, color: 'var(--neutral-500)' }}>
                        {stats.total || 0} {t('total records', 'kabuuang rekord')}
                    </span>
                </div>

                <div className="history-stats">
                    <div className="stat-card"><div className="stat-num green">{stats.healthy}</div><div className="stat-label">{t('Healthy', 'Malusog')}</div></div>
                    <div className="stat-card"><div className="stat-num mild">{stats.mild}</div><div className="stat-label">{t('Mild (≤ 25%)', 'Banayad')}</div></div>
                    <div className="stat-card"><div className="stat-num amber">{stats.moderate}</div><div className="stat-label">{t('Moderate', 'Katamtaman')}</div></div>
                    <div className="stat-card"><div className="stat-num red">{stats.severe}</div><div className="stat-label">{t('Severe', 'Malubha')}</div></div>
                </div>

                <div className="history-filter-wrap">
                    <input
                        type="text"
                        className="history-search-input"
                        placeholder={t('Search disease name, pathogen, or date...', 'Maghanap ng pangalan ng sakit, pathogen, o petsa...')}
                        value={searchQuery}
                        onChange={(e) => { setSearchQuery(e.target.value); setCurrentPage(1); }}
                    />
                    <div className="history-filter-chips">
                        {[
                            { key: 'all', label: t('All Scans', 'Lahat') },
                            { key: 'healthy', label: t('Healthy', 'Malusog') },
                            { key: 'mild', label: t('Mild', 'Banayad') },
                            { key: 'moderate', label: t('Moderate', 'Katamtaman') },
                            { key: 'severe', label: t('Severe', 'Malubha') },
                            { key: 'blast', label: 'Leaf Blast' },
                            { key: 'blb', label: 'BLB' },
                            { key: 'brown_spot', label: 'Brown Spot' },
                            { key: 'sheath_blight', label: 'Sheath Blight' },
                            { key: 'tungro', label: 'Tungro' },
                        ].map((chip) => (
                            <button
                                key={chip.key}
                                type="button"
                                className={`history-chip ${activeFilter === chip.key ? 'active' : ''}`}
                                onClick={() => handleFilterChange(chip.key)}
                            >
                                {chip.label}
                            </button>
                        ))}
                    </div>
                </div>

                {!filteredScans.length ? (
                    <div style={{ padding: 40, textAlign: 'center', color: 'var(--neutral-500)', fontSize: 13.5, background: '#fff', borderRadius: 16, border: '1px solid var(--neutral-200)' }}>
                        {t('No scan records found.', 'Walang nahanap na tala ng scan.')}
                        <br />
                        <button type="button" className="auth-btn" style={{ width: 'auto', display: 'inline-flex', marginTop: 14, padding: '10px 20px' }} onClick={() => navigate('/scan')}>
                            {t('Start a Scan', 'Simulan ang Scan')}
                        </button>
                    </div>
                ) : (
                    <div className="history-table-wrapper fade-in">
                        <div className="history-table-responsive">
                            <table className="history-data-table">
                                <thead>
                                    <tr>
                                        <th style={{ width: 70 }}>{t('Photo', 'Larawan')}</th>
                                        <th>{t('Disease Diagnosis', 'Diagnosis ng Sakit')}</th>
                                        <th>{t('Severity Level', 'Antas ng Severity')}</th>
                                        <th>{t('Date & Time', 'Petsa at Oras')}</th>
                                        <th style={{ textAlign: 'center', width: 130 }}>{t('Action', 'Aksyon')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {paginatedItems.map((scan) => (
                                        <tr key={scan.id || Math.random()}>
                                            <td>
                                                <div className={`ht-thumb ${scan.severity_class || 'blast-bg'}`} onClick={() => openScan(scan)} role="button" tabIndex={0}>
                                                    {scan.image_url ? (
                                                        <img src={resolveImageUrl(scan.image_url)} alt={scan.disease} />
                                                    ) : (
                                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" /><path d="M12 8v8M8 12h8" /></svg>
                                                    )}
                                                </div>
                                            </td>
                                            <td>
                                                <div className="ht-disease-name" style={{ cursor: 'pointer' }} onClick={() => openScan(scan)}>
                                                    {scan.disease}
                                                </div>
                                                <div className="ht-scientific-name">
                                                    {scan.scientific ? <em>{scan.scientific}</em> : (scan.severity === 'healthy' ? t('Optimal Plant Health', 'Malusog na Halaman') : t('Reference image match', 'Katugmang reference image'))}
                                                </div>
                                            </td>
                                            <td>
                                                {renderSeverityBadge(scan.severity)}
                                            </td>
                                            <td>
                                                <div className="ht-date">{scan.date || scan.dateLabel || 'Today'}</div>
                                                <div className="ht-time">{scan.time || ''}</div>
                                            </td>
                                            <td>
                                                <div className="ht-actions">
                                                    <button type="button" className="ht-view-btn" onClick={() => openScan(scan)}>
                                                        {t('View', 'Tingnan')}
                                                    </button>
                                                    {scan.id && (
                                                        <button type="button" className="ht-delete-btn" onClick={(e) => { e.stopPropagation(); deleteScan(scan.id); }} title="Delete">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="history-pagination-bar">
                            <div className="pagination-info">
                                {t('Showing', 'Ipinapakita')} {startIndex + 1}–{endIndex} {t('of', 'sa')} {filteredScans.length} {t('scan records', 'rekord')}
                            </div>
                            <div className="pagination-controls">
                                <button
                                    type="button"
                                    className="page-btn"
                                    disabled={currentPage <= 1}
                                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                                    title="Previous Page"
                                >
                                    ‹
                                </button>
                                {renderPaginationNumbers()}
                                <button
                                    type="button"
                                    className="page-btn"
                                    disabled={currentPage >= totalPages}
                                    onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                                    title="Next Page"
                                >
                                    ›
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                <div style={{ height: 80 }} />
                <BottomNav />
            </div>
        </AppLayout>
    );
}
