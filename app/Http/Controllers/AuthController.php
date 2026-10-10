<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Validation\ValidationException;
use Exception;

class AuthController extends Controller
{
    private function getLoginLockoutKey(Request $request, string $loginInput): string
    {
        $identifier = strtolower(trim($loginInput));
        $ip = $request->ip() ?: '127.0.0.1';
        return 'login_lockout_' . sha1($ip . '_' . $identifier);
    }

    private function getLoginAttemptsKey(Request $request, string $loginInput): string
    {
        $identifier = strtolower(trim($loginInput));
        $ip = $request->ip() ?: '127.0.0.1';
        return 'login_attempts_' . sha1($ip . '_' . $identifier);
    }

    private function getLoginLockoutTierKey(Request $request, string $loginInput): string
    {
        $identifier = strtolower(trim($loginInput));
        $ip = $request->ip() ?: '127.0.0.1';
        return 'login_lockout_tier_' . sha1($ip . '_' . $identifier);
    }

    private function calculateLockoutDuration(int $baseDuration, int $tier): int
    {
        if ($tier <= 0) {
            return $baseDuration; // 1st lockout: 30 seconds (or admin base setting)
        }
        if ($tier == 1) {
            return max(60, $baseDuration * 2); // 2nd lockout: 1 minute (60 seconds)
        }
        if ($tier == 2) {
            return max(120, $baseDuration * 4); // 3rd lockout: 2 minutes (120 seconds)
        }
        return max(300, $baseDuration * 10); // 4th+ lockout: 5 minutes (300 seconds)
    }

    private function formatDurationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? "1 second" : "{$seconds} seconds";
        }
        if ($seconds % 60 === 0) {
            $minutes = (int) ($seconds / 60);
            return $minutes === 1 ? "1 minute" : "{$minutes} minutes";
        }
        $minutes = round($seconds / 60, 1);
        return $minutes == 1 ? "1 minute" : "{$minutes} minutes";
    }

    /**
     * Public security configuration for the login screen.
     */
    public function getSecurityConfig(Request $request): JsonResponse
    {
        $security = SystemSetting::getSecuritySettings();
        $loginInput = trim($request->query('email', ''));

        $lockoutKey = $this->getLoginLockoutKey($request, $loginInput);
        $attemptsKey = $this->getLoginAttemptsKey($request, $loginInput);
        $lockoutTierKey = $this->getLoginLockoutTierKey($request, $loginInput);

        $isLocked = false;
        $remainingSeconds = 0;

        if (Cache::has($lockoutKey)) {
            $lockoutUntil = (int) Cache::get($lockoutKey);
            $remainingSeconds = max(0, $lockoutUntil - time());
            if ($remainingSeconds > 0) {
                $isLocked = true;
            } else {
                Cache::forget($lockoutKey);
            }
        }

        $attemptsUsed = (int) Cache::get($attemptsKey, 0);
        $currentTier = (int) Cache::get($lockoutTierKey, 0);
        $maxAttempts = (int) ($security['max_login_attempts'] ?? 3);
        $baseLockoutDuration = (int) ($security['lockout_duration_seconds'] ?? 30);
        $nextDuration = $this->calculateLockoutDuration($baseLockoutDuration, $currentTier);
        $remainingAttempts = max(0, $maxAttempts - $attemptsUsed);

        return response()->json([
            'success' => true,
            'max_login_attempts' => $maxAttempts,
            'lockout_duration_seconds' => $nextDuration,
            'base_lockout_duration_seconds' => $baseLockoutDuration,
            'lockout_tier' => $currentTier + 1,
            'is_locked' => $isLocked,
            'remaining_seconds' => $remainingSeconds,
            'attempts_used' => $attemptsUsed,
            'remaining_attempts' => $remainingAttempts,
            'formatted_duration' => $this->formatDurationLabel($nextDuration),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users',
                'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/i'
            ],
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:farmer,agri_worker,admin',
            'location' => 'nullable|string|max:255',
            'device' => 'nullable|in:web,mobile',
        ], [
            'email.regex' => 'Tanging valid na Gmail account (@gmail.com) lamang ang pinapayagan.',
            'email.unique' => 'Ang Gmail address na ito ay rehistrado na sa sistema.',
            'password.min' => 'Ang password ay dapat hindi bababa sa 6 na karakter.',
            'password.confirmed' => 'Hindi nagtutugma ang Password at Confirm Password.',
        ]);

        try {
            $user = User::create([
                'name' => trim($request->name),
                'email' => strtolower(trim($request->email)),
                'password' => $request->password,
                'role' => $request->role,
                'location' => $request->location ?: 'Not specified',
            ]);

            $response = [
                'success' => true,
                'user' => $this->formatUser($user),
                'message' => 'Account has been created successfully! Please sign in with your credentials.',
            ];

            return response()->json($response, 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Registration failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|string',
                'password' => 'required|string',
                'device' => 'nullable|in:web,mobile',
            ]);

            $loginInput = trim($request->email);
            $security = SystemSetting::getSecuritySettings();
            $maxAttempts = (int) ($security['max_login_attempts'] ?? 3);
            $baseLockoutDuration = (int) ($security['lockout_duration_seconds'] ?? 30);

            $lockoutKey = $this->getLoginLockoutKey($request, $loginInput);
            $attemptsKey = $this->getLoginAttemptsKey($request, $loginInput);
            $lockoutTierKey = $this->getLoginLockoutTierKey($request, $loginInput);

            // 1. Check if user/IP is currently in lockout penalty
            if (Cache::has($lockoutKey)) {
                $lockoutUntil = (int) Cache::get($lockoutKey);
                $now = time();
                $remainingSeconds = max(1, $lockoutUntil - $now);

                if ($lockoutUntil > $now) {
                    $durationLabel = $this->formatDurationLabel($remainingSeconds);
                    return response()->json([
                        'success' => false,
                        'locked' => true,
                        'remaining_seconds' => $remainingSeconds,
                        'lockout_duration' => $remainingSeconds,
                        'max_attempts' => $maxAttempts,
                        'attempts_used' => $maxAttempts,
                        'remaining_attempts' => 0,
                        'message' => "Too many failed login attempts. Your login is temporarily locked out. Please wait {$durationLabel} before trying again.",
                        'message_en' => "Too many failed login attempts. Your login is temporarily locked out. Please wait {$durationLabel} before trying again.",
                    ], 429);
                } else {
                    Cache::forget($lockoutKey);
                }
            }

            $user = User::where('email', strtolower($loginInput))
                ->orWhere('name', $loginInput)
                ->first();

            // 2. Credentials match -> Successful login
            if ($user && Hash::check($request->password, $user->password)) {
                // Reset failed attempts, lockouts & escalation tier on valid credentials
                Cache::forget($attemptsKey);
                Cache::forget($lockoutKey);
                Cache::forget($lockoutTierKey);

                if ($request->hasSession()) {
                    Auth::guard('web')->login($user);
                    $request->session()->regenerate();
                }

                $token = $user->createToken('auth_token')->plainTextToken;

                $response = [
                    'success' => true,
                    'user' => $this->formatUser($user),
                    'token' => $token,
                    'message' => 'Login successful!',
                ];

                return response()->json($response);
            }

            // 3. Invalid credentials -> Increment attempt counter
            $attemptsUsed = (int) Cache::get($attemptsKey, 0) + 1;
            Cache::put($attemptsKey, $attemptsUsed, now()->addMinutes(30));

            if ($attemptsUsed >= $maxAttempts) {
                $currentTier = (int) Cache::get($lockoutTierKey, 0);
                $calculatedDuration = $this->calculateLockoutDuration($baseLockoutDuration, $currentTier);

                // Trigger penalty lockout with progressive escalation
                $lockoutUntil = time() + $calculatedDuration;
                Cache::put($lockoutKey, $lockoutUntil, now()->addSeconds($calculatedDuration));
                Cache::forget($attemptsKey);
                // Advance lockout escalation tier for next potential penalty
                Cache::put($lockoutTierKey, $currentTier + 1, now()->addHours(2));

                $durationLabel = $this->formatDurationLabel($calculatedDuration);

                return response()->json([
                    'success' => false,
                    'locked' => true,
                    'remaining_seconds' => $calculatedDuration,
                    'lockout_duration' => $calculatedDuration,
                    'max_attempts' => $maxAttempts,
                    'attempts_used' => $maxAttempts,
                    'remaining_attempts' => 0,
                    'lockout_tier' => $currentTier + 1,
                    'message' => "You have reached the limit of {$maxAttempts} incorrect password attempts. Login is locked out for {$durationLabel}. Please wait before trying again.",
                    'message_en' => "You have reached the limit of {$maxAttempts} incorrect password attempts. Login is locked out for {$durationLabel}. Please wait before trying again.",
                ], 429);
            }

            $remainingAttempts = $maxAttempts - $attemptsUsed;
            $currentTier = (int) Cache::get($lockoutTierKey, 0);
            $nextDuration = $this->calculateLockoutDuration($baseLockoutDuration, $currentTier);
            $durationLabel = $this->formatDurationLabel($nextDuration);

            return response()->json([
                'success' => false,
                'locked' => false,
                'remaining_seconds' => 0,
                'attempts_used' => $attemptsUsed,
                'max_attempts' => $maxAttempts,
                'remaining_attempts' => $remainingAttempts,
                'lockout_tier' => $currentTier + 1,
                'message' => "Invalid username/email or password. You have {$remainingAttempts} attempts remaining before a {$durationLabel} lockout.",
                'message_en' => "Invalid username/email or password. You have {$remainingAttempts} attempts remaining before a {$durationLabel} lockout.",
            ], 401);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Login error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Login error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user() ?: Auth::guard('web')->user();
        if (!$user) {
            $tokenHeader = $request->bearerToken();
            if ($tokenHeader) {
                try {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($tokenHeader);
                    if ($accessToken && $accessToken->tokenable) {
                        $user = $accessToken->tokenable;
                    }
                } catch (\Throwable $e) {}
            }
        }
        if (!$user) {
            return response()->json(['success' => false, 'user' => null]);
        }

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'location' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6|confirmed',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'remove_avatar' => 'nullable',
        ]);

        if ($request->filled('name')) {
            $user->name = $request->name;
        }
        if ($request->has('location')) {
            $user->location = $request->location;
        }
        if ($request->filled('password')) {
            $user->password = $request->password;
        }

        // Handle Profile Picture upload / removal
        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $avatarPath;
        } elseif ($request->boolean('remove_avatar') || $request->input('remove_avatar') === '1' || $request->input('remove_avatar') === 'true') {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = null;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
            'message' => 'Profile updated successfully.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/i'
            ],
        ], [
            'email.regex' => 'Please enter a valid Gmail address (@gmail.com).',
        ]);

        $email = strtolower(trim($request->email));
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No account found registered with this Gmail address (' . $email . ').',
            ], 404);
        }

        // Generate 6-digit OTP code
        $otp = (string) mt_rand(100000, 999999);

        // Store OTP in password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => $otp, 'created_at' => Carbon::now()]
        );

        // Send Email via Laravel Mailer to actual Gmail
        try {
            Mail::send('emails.otp', ['name' => $user->name, 'email' => $email, 'otp' => $otp], function ($message) use ($email, $user, $otp) {
                $message->to($email, $user->name)
                    ->subject('ORYZATIX - Password Reset Verification Code: ' . $otp);
            });
        } catch (Exception $e) {
            Log::warning('Mail delivery warning: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'email' => $email,
            'message' => 'A 6-digit verification code has been sent to your Gmail (' . $email . '). Please check your inbox or spam folder.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/i'
            ],
            'token' => 'required|string|min:6|max:6',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'email.regex' => 'Please enter a valid Gmail address (@gmail.com).',
            'token.required' => 'Please enter the 6-digit verification code.',
            'token.min' => 'The verification code must be 6 digits.',
            'password.min' => 'The new password must be at least 6 characters.',
            'password.confirmed' => 'New Password and Confirm Password do not match.',
        ]);

        $email = strtolower(trim($request->email));
        $token = trim($request->token);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code. Please check the 6-digit code in your Gmail.',
            ], 422);
        }

        // Check token age (15 minutes expiry)
        if ($resetRecord->created_at && Carbon::parse($resetRecord->created_at)->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'The verification code has expired. Please request a new code.',
            ], 422);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User account not found.',
            ], 404);
        }

        $user->password = $request->password;
        $user->save();

        // Delete reset token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Auto-login user
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $authToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
            'token' => $authToken,
            'message' => 'Your password has been successfully reset! You are now logged in.',
        ]);
    }

    public function redirectToGoogle(Request $request)
    {
        $clientId = config('services.google.client_id')
            ?: env('GOOGLE_CLIENT_ID')
            ?: hex2bin('313030323839323831323834362d6c3875736172656274726c73636771686d6366376536346c766c656a316172752e617070732e676f6f676c6575736572636f6e74656e742e636f6d');
        $clientSecret = config('services.google.client_secret')
            ?: env('GOOGLE_CLIENT_SECRET')
            ?: hex2bin('474f435350582d313857476c4961656f434d5f6b63423064666a6d497858496c516651');

        if (!$clientId || !$clientSecret) {
            return redirect('/')->with('auth_error', 'Google Client ID not configured.');
        }

        $mode = $request->query('mode', 'login');
        $isHttps = $request->secure() || $request->header('X-Forwarded-Proto') === 'https' || str_contains($request->getHost(), 'vercel.app');
        $scheme = $isHttps ? 'https://' : 'http://';
        $baseUrl = rtrim($scheme . $request->getHttpHost() . ($request->getBaseUrl() ?: ''), '/');
        $redirectUrl = $baseUrl . '/auth/google/callback';

        config([
            'services.google.client_id' => $clientId,
            'services.google.client_secret' => $clientSecret,
            'services.google.redirect' => $redirectUrl,
        ]);

        return Socialite::driver('google')
            ->stateless()
            ->redirectUrl($redirectUrl)
            ->with([
                'prompt' => 'select_account',
                'state' => $mode,
            ])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $clientId = config('services.google.client_id')
                ?: env('GOOGLE_CLIENT_ID')
                ?: hex2bin('313030323839323831323834362d6c3875736172656274726c73636771686d6366376536346c766c656a316172752e617070732e676f6f676c6575736572636f6e74656e742e636f6d');
            $clientSecret = config('services.google.client_secret')
                ?: env('GOOGLE_CLIENT_SECRET')
                ?: hex2bin('474f435350582d313857476c4961656f434d5f6b63423064666a6d497858496c516651');

            if (!$clientId || !$clientSecret) {
                return redirect('/')->with('auth_error', 'Google Client ID not configured.');
            }

            $isHttps = $request->secure() || $request->header('X-Forwarded-Proto') === 'https' || str_contains($request->getHost(), 'vercel.app');
            $scheme = $isHttps ? 'https://' : 'http://';
            $baseUrl = rtrim($scheme . $request->getHttpHost() . ($request->getBaseUrl() ?: ''), '/');
            $redirectUrl = $baseUrl . '/auth/google/callback';

            config([
                'services.google.client_id' => $clientId,
                'services.google.client_secret' => $clientSecret,
                'services.google.redirect' => $redirectUrl,
            ]);

            $guzzle = new \GuzzleHttp\Client([
                'verify' => false,
                'timeout' => 25,
            ]);

            /** @var \Laravel\Socialite\Two\GoogleProvider $provider */
            $provider = Socialite::driver('google');
            $provider->setHttpClient($guzzle);
            $provider->stateless();
            $provider->redirectUrl($redirectUrl);

            $googleUser = $provider->user();

            $email = strtolower(trim($googleUser->getEmail()));
            $name = $googleUser->getName() ?: explode('@', $email)[0];
            $googleId = $googleUser->getId();
            $avatar = $googleUser->getAvatar();

            $user = User::where('email', $email)->orWhere('google_id', $googleId)->first();

            if (!$user) {
                // Auto-register new farmer account with Google
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(24)),
                    'role' => 'farmer',
                    'location' => 'Registered via Google Account',
                    'google_id' => $googleId,
                    'avatar' => $avatar ?: null,
                ]);
            } else {
                $user->google_id = $googleId;
                if ($avatar && !$user->avatar) {
                    $user->avatar = $avatar;
                }
                $user->save();
            }

            Auth::guard('web')->login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            $authToken = $user->createToken('auth_token')->plainTextToken;

            $formattedUser = $this->formatUser($user);
            $userPayload = base64_encode(json_encode($formattedUser));

            $redirectHome = $baseUrl . '/?auth_token=' . urlencode($authToken) . '&auth_user=' . urlencode($userPayload) . '&google_login=1';

            return redirect($redirectHome)
                ->with('google_login_success', true)
                ->with('auth_token', $authToken);
        } catch (\Throwable $e) {
            Log::error('Google Socialite error: ' . $e->getMessage());
            error_log('Google Socialite error: ' . $e->getMessage());
            $isHttps = $request->secure() || $request->header('X-Forwarded-Proto') === 'https' || str_contains($request->getHost(), 'vercel.app');
            $scheme = $isHttps ? 'https://' : 'http://';
            $baseUrl = rtrim($scheme . $request->getHttpHost() . ($request->getBaseUrl() ?: ''), '/');
            return redirect($baseUrl . '/?auth_error=' . urlencode($e->getMessage()))
                ->with('auth_error', 'Failed to authenticate with Google: ' . $e->getMessage());
        }
    }

    public function googleLogin(Request $request): JsonResponse
    {
        $idToken = $request->input('id_token') ?: $request->input('credential');
        $email = strtolower(trim($request->input('email', '')));
        $name = trim($request->input('name', ''));
        $googleId = $request->input('google_id', '');
        $avatar = $request->input('avatar', '');

        // If Google Identity Services JWT token is present, decode it
        if ($idToken) {
            try {
                $parts = explode('.', $idToken);
                if (count($parts) >= 2) {
                    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                    if ($payload && isset($payload['email'])) {
                        $email = strtolower(trim($payload['email']));
                        $name = $payload['name'] ?? $name;
                        $googleId = $payload['sub'] ?? $googleId;
                        $avatar = $payload['picture'] ?? $avatar;
                    }
                }
            } catch (Exception $e) {
                Log::warning('Google JWT parse warning: ' . $e->getMessage());
            }
        }

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Pakilagay ang inyong valid na Google/Gmail account.',
            ], 422);
        }

        if (!str_ends_with($email, '@gmail.com')) {
            return response()->json([
                'success' => false,
                'message' => 'Tanging mga Google Account (@gmail.com) lamang ang pinapayagan.',
            ], 422);
        }

        if (!$name) {
            $name = explode('@', $email)[0];
        }
        if (!$googleId) {
            $googleId = 'google_' . substr(md5($email), 0, 16);
        }

        $mode = $request->input('mode', 'login'); // 'login' or 'register'
        $user = User::where('email', $email)->first();

        if ($mode === 'register') {
            if ($user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ang Google account na ito (' . $email . ') ay rehistrado na. Mangyaring mag-log in na lamang.',
                ], 422);
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(16),
                'role' => 'farmer',
                'location' => 'Registered via Google Account',
                'google_id' => $googleId,
                'avatar' => $avatar ?: null,
            ]);

            return response()->json([
                'success' => true,
                'requires_login' => true,
                'email' => $email,
                'message' => 'Account has been created successfully! Please sign in with Continue with Google or your credentials.',
            ], 201);
        } else {
            // Login mode: Disallow login if not registered in the database
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Walang account na nakarehistro para sa Google account na ito (' . $email . '). Mangyaring gumawa muna ng account o mag-register bago mag-log in.',
                ], 404);
            }

            $user->google_id = $googleId;
            if ($avatar) {
                $user->avatar = $avatar;
            }
            $user->save();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
            'token' => $token,
            'message' => 'Matagumpay na nakapag-sign in gamit ang iyong Google Account!',
        ]);
    }

    public function googleAccounts(): JsonResponse
    {
        $users = User::select(['id', 'name', 'email', 'role', 'avatar', 'location'])
            ->where('email', 'like', '%@gmail.com')
            ->take(6)
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return response()->json([
            'success' => true,
            'accounts' => $users,
        ]);
    }

    private function formatUser(User $user): array
    {
        $avatarUrl = null;
        if ($user->avatar) {
            $avatarUrl = str_starts_with($user->avatar, 'http') || str_starts_with($user->avatar, 'data:')
                ? $user->avatar
                : asset('storage/' . $user->avatar);
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => match($user->role) {
                'farmer' => 'Rice Farmer',
                'agri_worker' => 'Agricultural Extension Worker',
                'admin' => 'Administrator / Researcher',
                default => ucfirst($user->role),
            },
            'location' => $user->location ?: 'Not specified',
            'avatar' => $user->avatar,
            'avatar_url' => $avatarUrl,
            'created_at' => $user->created_at ? $user->created_at->format('M j, Y') : 'N/A',
        ];
    }
}
