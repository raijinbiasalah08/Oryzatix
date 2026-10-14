import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import api, { setAuthToken, getAuthToken, ensureCsrfCookie } from '../api/client';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    const fetchUser = useCallback(async () => {
        try {
            const { data } = await api.get('/auth/user');
            if (data.success && data.user) {
                setUser(data.user);
            } else {
                setUser(null);
            }
        } catch (err) {
            if (err.response?.status !== 401) {
                console.error('Auth check failed:', err);
            }
            setUser(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchUser();
    }, [fetchUser]);

    const login = async (email, password, device = 'web') => {
        await ensureCsrfCookie();
        const { data } = await api.post('/auth/login', { email, password, device });
        if (data.success) {
            if (data.token) setAuthToken(data.token);
            setUser(data.user);
        }
        return data;
    };

    const register = async (payload) => {
        await ensureCsrfCookie();
        const { data } = await api.post('/auth/register', { ...payload, device: payload.device || 'web' });
        if (data.success) {
            if (data.token) {
                setAuthToken(data.token);
                setUser(data.user);
            }
        }
        return data;
    };

    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            setAuthToken(null);
            setUser(null);
        }
    };

    const updateProfile = async (payload) => {
        const { data } = await api.put('/auth/profile', payload);
        if (data.success) setUser(data.user);
        return data;
    };

    return (
        <AuthContext.Provider value={{ user, loading, login, register, logout, updateProfile, refreshUser: fetchUser, isAuthenticated: !!user, token: getAuthToken() }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used within AuthProvider');
    return ctx;
}
