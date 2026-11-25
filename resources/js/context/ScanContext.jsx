import { createContext, useContext, useState, useCallback } from 'react';
import api from '../api/client';

const ScanContext = createContext(null);

export function ScanProvider({ children }) {
    const [currentScan, setCurrentScan] = useState(null);
    const [recentScans, setRecentScans] = useState([]);
    const [recentScansTick, setRecentScansTick] = useState(0);

    const loadRecentScans = useCallback(async () => {
        try {
            const { data } = await api.get('/rice-detector/history');
            if (!data.success) return;
            const all = [];
            Object.values(data.scans || {}).forEach((group) => group.forEach((s) => all.push(s)));
            setRecentScans(all);
        } catch {
            /* keep existing list on transient errors */
        }
    }, []);

    const markScanSaved = useCallback((scan) => {
        if (!scan) return;

        setRecentScans((prev) => [scan, ...prev.filter((s) => s.id !== scan.id)]);
        setRecentScansTick((tick) => tick + 1);
    }, []);

    return (
        <ScanContext.Provider value={{
            currentScan,
            setCurrentScan,
            recentScans,
            loadRecentScans,
            markScanSaved,
            recentScansTick,
        }}>
            {children}
        </ScanContext.Provider>
    );
}

export function useScan() {
    const ctx = useContext(ScanContext);
    if (!ctx) throw new Error('useScan must be used within ScanProvider');
    return ctx;
}
