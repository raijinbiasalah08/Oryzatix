import 'dart:async';
import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/storage/token_storage.dart';
import '../models/user_model.dart';

class AuthProvider extends ChangeNotifier with WidgetsBindingObserver {
  UserModel? _currentUser;
  bool _isLoading = false;
  bool _isCheckingAuth = true;
  String? _errorMessage;

  int _lockoutSeconds = 0;
  Timer? _lockoutTimer;

  UserModel? get currentUser => _currentUser;
  bool get isAuthenticated => _currentUser != null;
  bool get isLoading => _isLoading;
  bool get isCheckingAuth => _isCheckingAuth;
  String? get errorMessage => _errorMessage;
  int get lockoutSeconds => _lockoutSeconds;
  bool get isLockedOut => _lockoutSeconds > 0;

  AuthProvider() {
    WidgetsBinding.instance.addObserver(this);
    checkAuth();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.detached) {
      unawaited(_expireSessionOnAppClose());
    }
  }

  Future<void> _expireSessionOnAppClose() async {
    try {
      await ApiClient.post(ApiEndpoints.logout);
    } catch (_) {
      // The OS may terminate the process before this request can finish.
    } finally {
      await TokenStorage.clearAuth();
      _currentUser = null;
    }
  }

  void _startLockoutTimer(int seconds) {
    _lockoutTimer?.cancel();
    _lockoutSeconds = seconds;
    notifyListeners();

    _lockoutTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_lockoutSeconds > 0) {
        _lockoutSeconds--;
        notifyListeners();
      } else {
        timer.cancel();
        _lockoutTimer = null;
        _errorMessage = null;
        notifyListeners();
      }
    });
  }

  Future<void> checkAuth() async {
    _isCheckingAuth = true;
    notifyListeners();

    try {
      final token = await TokenStorage.getToken();
      if (token != null && token.isNotEmpty) {
        // Try to fetch fresh user from server
        try {
          final res = await ApiClient.get(ApiEndpoints.user);
          if (res is Map<String, dynamic> && res['success'] == true && res['user'] != null) {
            _currentUser = UserModel.fromJson(res['user']);
            await TokenStorage.saveUser(res['user']);
          }
        } on ApiException catch (e) {
          if (e.statusCode == 401 || e.statusCode == 403) {
            await TokenStorage.clearAuth();
            _currentUser = null;
          } else {
            // A network/server failure may use the cached offline identity.
            final cached = await TokenStorage.getUser();
            if (cached != null) {
              _currentUser = UserModel.fromJson(cached);
            }
          }
        } catch (_) {
          final cached = await TokenStorage.getUser();
          if (cached != null) {
            _currentUser = UserModel.fromJson(cached);
          }
        }
      } else {
        _currentUser = null;
      }
    } catch (_) {}

    _isCheckingAuth = false;
    notifyListeners();
  }

  Future<bool> login({required String email, required String password}) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await ApiClient.post(ApiEndpoints.login, body: {
        'email': email,
        'password': password,
      });

      if (res is Map<String, dynamic> && res['success'] == true) {
        final token = res['token']?.toString();
        if (token != null) {
          await TokenStorage.saveToken(token);
        }
        if (res['user'] != null) {
          _currentUser = UserModel.fromJson(res['user']);
          await TokenStorage.saveUser(res['user']);
        }
        _isLoading = false;
        notifyListeners();
        return true;
      } else {
        _errorMessage = res['message']?.toString() ?? 'Login failed.';
      }
    } on ApiException catch (e) {
      _errorMessage = e.message;
      if (e.isLocked && e.remainingSeconds > 0) {
        _startLockoutTimer(e.remainingSeconds);
      }
    } catch (e) {
      _errorMessage = 'Login failed: ${e.toString()}';
    }

    _isLoading = false;
    notifyListeners();
    return false;
  }

  Future<bool> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
    String? location,
  }) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await ApiClient.post(ApiEndpoints.register, body: {
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': passwordConfirmation,
        'location': location ?? 'Roxas, Oriental Mindoro',
      });

      if (res is Map<String, dynamic> && res['success'] == true) {
        final token = res['token']?.toString();
        if (token != null) {
          await TokenStorage.saveToken(token);
        }
        if (token != null && res['user'] != null) {
          _currentUser = UserModel.fromJson(res['user']);
          await TokenStorage.saveUser(res['user']);
        }
        _isLoading = false;
        notifyListeners();
        return true;
      } else {
        _errorMessage = res['message']?.toString() ?? 'Registration failed.';
      }
    } on ApiException catch (e) {
      _errorMessage = e.message;
    } catch (e) {
      _errorMessage = 'Registration failed: ${e.toString()}';
    }

    _isLoading = false;
    notifyListeners();
    return false;
  }

  Future<void> logout() async {
    _isLoading = true;
    notifyListeners();

    try {
      await ApiClient.post(ApiEndpoints.logout);
    } catch (_) {}

    await TokenStorage.clearAuth();
    _currentUser = null;
    _isLoading = false;
    notifyListeners();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _lockoutTimer?.cancel();
    super.dispose();
  }
}
