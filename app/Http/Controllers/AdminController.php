<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\RiceScan;
use App\Models\Disease;
use App\Models\ChatMessage;
use App\Models\ChatbotKnowledge;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Exception;

class AdminController extends Controller
{
    protected function checkAdmin(Request $request): ?JsonResponse
    {
        $currentUser = $request->user() ?: Auth::user();
        if (!$currentUser || $currentUser->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }
        return null;
    }

    /**
     * Get all users categorized by Farmers and Staff, plus summary statistics.
     */
    public function index(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        try {
            $farmers = User::where('role', 'farmer')
                ->withCount('scans')
                ->latest()
                ->get()
                ->map(fn($user) => $this->formatUser($user));

            $staff = User::whereIn('role', ['agri_worker', 'admin'])
                ->withCount('scans')
                ->latest()
                ->get()
                ->map(fn($user) => $this->formatUser($user));

            $totalScans = RiceScan::count();
            $allUsers = $farmers->concat($staff)->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $allUsers,
                    'farmers' => $farmers,
                    'staff' => $staff,
                    'stats' => [
                        'total_farmers' => $farmers->count(),
                        'total_staff' => $staff->count(),
                        'total_admins' => User::where('role', 'admin')->count(),
                        'total_users' => $farmers->count() + $staff->count(),
                        'total_scans' => $totalScans,
                    ]
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Admin users fetch error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load user accounts.',
            ], 500);
        }
    }

    /**
     * Get comprehensive system-wide overview and analytics for Admin Dashboard.
     */
    public function overview(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        try {
            $totalFarmers = User::where('role', 'farmer')->count();
            $totalStaff = User::where('role', 'agri_worker')->count();
            $totalAdmins = User::where('role', 'admin')->count();
            $totalUsers = User::count();

            $totalScans = RiceScan::count();
            $todayScans = RiceScan::whereDate('created_at', today())->count();

            // Disease vs Healthy counts
            $healthyCount = RiceScan::where(function($q) {
                $q->where('disease_name', 'like', '%Healthy%')
                  ->orWhere('severity', 'healthy');
            })->count();
            $diseasedCount = max(0, $totalScans - $healthyCount);

            // Disease breakdown
            $blbCount = RiceScan::where(function($q) {
                $q->where('disease_name', 'like', '%Bacterial%')->orWhere('disease_name', 'blb');
            })->count();
            $blastCount = RiceScan::where(function($q) {
                $q->where('disease_name', 'like', '%Blast%')->orWhere('disease_name', 'blast');
            })->count();
            $brownSpotCount = RiceScan::where(function($q) {
                $q->where('disease_name', 'like', '%Brown Spot%')->orWhere('disease_name', 'brown_spot');
            })->count();
            $sheathBlightCount = RiceScan::where(function($q) {
                $q->where('disease_name', 'like', '%Sheath%')->orWhere('disease_name', 'sheath_blight');
            })->count();
            $tungroCount = RiceScan::where(function($q) {
                $q->where('disease_name', 'like', '%Tungro%')->orWhere('disease_name', 'tungro');
            })->count();

            $activeOutbreaks = RiceScan::whereIn('severity', ['moderate', 'severe'])->count();
            $advisoriesCount = RiceScan::whereNotNull('notes')->where('notes', '!=', '')->count();
            $healthyRatio = $totalScans > 0 ? round(($healthyCount / $totalScans) * 100, 1) : 0.0;

            // Most detected disease
            $diseaseRankings = [
                'Rice Leaf Blast' => $blastCount,
                'Bacterial Leaf Blight' => $blbCount,
                'Sheath Blight' => $sheathBlightCount,
                'Rice Tungro Disease' => $tungroCount,
                'Brown Spot' => $brownSpotCount,
            ];
            arsort($diseaseRankings);
            $mostDetectedDisease = array_key_first($diseaseRankings);
            $mostDetectedCount = reset($diseaseRankings);
            $mostDetectedPercent = $totalScans > 0 ? round(($mostDetectedCount / $totalScans) * 100, 1) : 0;

            // Disease distribution percentages
            $calcPercent = fn($cnt) => $totalScans > 0 ? round(($cnt / $totalScans) * 100, 1) : 0;
            $diseaseDistribution = [
                'blast' => ['count' => $blastCount, 'percent' => $calcPercent($blastCount), 'label' => 'Leaf Blast', 'severity' => 'Severe'],
                'blb' => ['count' => $blbCount, 'percent' => $calcPercent($blbCount), 'label' => 'Bacterial Leaf Blight', 'severity' => 'Moderate'],
                'sheath_blight' => ['count' => $sheathBlightCount, 'percent' => $calcPercent($sheathBlightCount), 'label' => 'Sheath Blight', 'severity' => 'Moderate'],
                'tungro' => ['count' => $tungroCount, 'percent' => $calcPercent($tungroCount), 'label' => 'Rice Tungro Disease', 'severity' => 'Severe'],
                'brown_spot' => ['count' => $brownSpotCount, 'percent' => $calcPercent($brownSpotCount), 'label' => 'Brown Spot', 'severity' => 'Moderate'],
                'healthy' => ['count' => $healthyCount, 'percent' => $calcPercent($healthyCount), 'label' => 'Healthy Rice Leaves', 'severity' => 'Optimal'],
            ];

            // 7 Days detection statistics / trend for charts
            $dailyTrend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::today()->subDays($i);
                $dScans = RiceScan::whereDate('created_at', $date)->count();
                $dDiseased = RiceScan::whereDate('created_at', $date)->whereNotIn('severity', ['healthy'])->count();
                $dHealthy = RiceScan::whereDate('created_at', $date)->where('severity', 'healthy')->count();

                $dailyTrend[] = [
                    'date' => $date->format('M j'),
                    'day_name' => $date->format('D'),
                    'total' => $dScans,
                    'diseased' => $dDiseased,
                    'healthy' => $dHealthy,
                ];
            }

            // Recent system-wide scans
            $recentScans = RiceScan::with('user')
                ->latest()
                ->take(10)
                ->get()
                ->map(fn($scan) => $this->formatScan($scan));

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => [
                        'total_users' => $totalUsers,
                        'total_farmers' => $totalFarmers,
                        'total_staff' => $totalStaff,
                        'total_admins' => $totalAdmins,
                        'total_scans' => $totalScans,
                        'today_scans' => $todayScans,
                        'detected_diseases' => $diseasedCount,
                        'healthy_leaves' => $healthyCount,
                        'healthy_ratio' => $healthyRatio,
                        'active_outbreaks' => $activeOutbreaks,
                        'advisories_count' => $advisoriesCount,
                        'most_detected_disease' => $mostDetectedDisease,
                        'most_detected_count' => $mostDetectedCount,
                        'most_detected_percent' => $mostDetectedPercent,
                    ],
                    'disease_distribution' => $diseaseDistribution,
                    'daily_trend' => $dailyTrend,
                    'recent_scans' => $recentScans,
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Admin overview fetch error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load admin telemetry overview.',
            ], 500);
        }
    }

    /**
     * Create a new user (Staff or Farmer) from Admin panel.
     */
    public function store(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

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
            'password' => 'required|string|min:6',
            'role' => 'required|in:farmer,agri_worker,admin',
            'location' => 'nullable|string|max:255',
        ], [
            'email.regex' => 'Tanging valid na Gmail address (@gmail.com) ang pinapayagan.',
            'email.unique' => 'Ang Gmail address na ito ay rehistrado na.',
        ]);

        try {
            $user = User::create([
                'name' => trim($request->name),
                'email' => strtolower(trim($request->email)),
                'password' => $request->password,
                'role' => $request->role,
                'location' => $request->location,
            ]);

            return response()->json([
                'success' => true,
                'user' => $this->formatUser($user),
                'message' => 'Account created successfully!',
            ], 201);
        } catch (Exception $e) {
            Log::error('Admin create user error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create account.',
            ], 500);
        }
    }

    /**
     * Update an existing user account.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                'max:255',
                'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/i',
                Rule::unique('users')->ignore($user->id)
            ],
            'role' => 'sometimes|in:farmer,agri_worker,admin',
            'location' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'remove_avatar' => 'nullable',
        ], [
            'email.regex' => 'Tanging valid na Gmail address (@gmail.com) ang pinapayagan.',
        ]);

        try {
            if ($request->filled('name')) {
                $user->name = $request->name;
            }
            if ($request->filled('email')) {
                $user->email = $request->email;
            }
            if ($request->filled('role')) {
                $user->role = $request->role;
            }
            if ($request->has('location')) {
                $user->location = $request->location;
            }
            if ($request->filled('password')) {
                $user->password = $request->password;
            }

            if ($request->hasFile('avatar')) {
                if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                    Storage::disk('public')->delete($user->avatar);
                }
                $user->avatar = $request->file('avatar')->store('avatars', 'public');
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
                'message' => 'Account updated successfully!',
            ]);
        } catch (Exception $e) {
            Log::error('Admin update user error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update account.',
            ], 500);
        }
    }

    /**
     * Delete a user account.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $currentUser = $request->user() ?: Auth::user();
        if ($currentUser && $currentUser->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own admin account while logged in.',
            ], 400);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        try {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Account deleted successfully.',
            ]);
        } catch (Exception $e) {
            Log::error('Admin delete user error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete account.',
            ], 500);
        }
    }

    public function getUserScans(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $user = User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $scans = RiceScan::where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(fn($scan) => $this->formatScan($scan));

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
            'scans' => $scans,
            'total_scans' => $scans->count(),
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * 3. DISEASE MANAGEMENT (CRUD + Image Upload + Status)
     * ══════════════════════════════════════════════════════════════════ */
    public function getDiseases(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        try {
            $diseases = Disease::latest()->get()->map(fn($d) => $this->formatDisease($d));

            return response()->json([
                'success' => true,
                'data' => [
                    'diseases' => $diseases,
                ],
                'total' => $diseases->count(),
            ]);
        } catch (Exception $e) {
            Log::error('Admin diseases fetch error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load disease records.'], 500);
        }
    }

    public function storeDisease(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $name = trim((string)$request->name);
        $code = $request->code ? strtolower(str_replace(' ', '_', trim($request->code))) : \Illuminate\Support\Str::slug($name, '_');
        if (empty($code)) {
            $code = 'disease_' . time();
        }

        // Ensure unique code
        $originalCode = $code;
        $counter = 1;
        while (Disease::where('code', $code)->exists()) {
            $code = "{$originalCode}_{$counter}";
            $counter++;
        }

        $status = in_array($request->status, ['active', '1', 1, true, 'true'], true) ? 'active' : 'inactive';
        if ($request->has('is_active')) {
            $status = in_array($request->is_active, ['active', '1', 1, true, 'true'], true) ? 'active' : 'inactive';
        }

        $treatment = $request->recommended_treatment ?: $request->treatment;

        $request->validate([
            'name' => 'required|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'symptoms' => 'nullable|string',
            'causes' => 'nullable|string',
            'prevention' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        try {
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('diseases', 'public');
            }

            $chem = is_string($request->chemical_treatments) ? json_decode($request->chemical_treatments, true) : $request->chemical_treatments;
            $org = is_string($request->organic_treatments) ? json_decode($request->organic_treatments, true) : $request->organic_treatments;

            $disease = Disease::create([
                'name' => $name,
                'code' => $code,
                'scientific_name' => $request->scientific_name,
                'image_path' => $imagePath,
                'description' => $request->description,
                'symptoms' => $request->symptoms,
                'causes' => $request->causes,
                'prevention' => $request->prevention,
                'recommended_treatment' => $treatment,
                'chemical_treatments' => $chem,
                'organic_treatments' => $org,
                'status' => $status,
            ]);

            return response()->json([
                'success' => true,
                'disease' => $this->formatDisease($disease),
                'message' => 'Disease information added successfully!',
            ], 201);
        } catch (Exception $e) {
            Log::error('Admin store disease error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to add disease.'], 500);
        }
    }

    public function updateDisease(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $disease = Disease::find($id);
        if (!$disease) {
            return response()->json(['success' => false, 'message' => 'Disease not found.'], 404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', 'max:100', Rule::unique('diseases')->ignore($disease->id)],
            'scientific_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'symptoms' => 'nullable|string',
            'causes' => 'nullable|string',
            'prevention' => 'nullable|string',
            'recommended_treatment' => 'nullable|string',
            'treatment' => 'nullable|string',
            'chemical_treatments' => 'nullable',
            'organic_treatments' => 'nullable',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        try {
            if ($request->filled('name')) $disease->name = $request->name;
            if ($request->filled('code')) $disease->code = strtolower(str_replace(' ', '_', $request->code));
            if ($request->has('scientific_name')) $disease->scientific_name = $request->scientific_name;
            if ($request->has('description')) $disease->description = $request->description;
            if ($request->has('symptoms')) $disease->symptoms = $request->symptoms;
            if ($request->has('causes')) $disease->causes = $request->causes;
            if ($request->has('prevention')) $disease->prevention = $request->prevention;
            
            if ($request->has('recommended_treatment') || $request->has('treatment')) {
                $disease->recommended_treatment = $request->recommended_treatment ?: $request->treatment;
            }

            if ($request->has('status')) {
                $disease->status = in_array($request->status, ['active', '1', 1, true, 'true'], true) ? 'active' : 'inactive';
            } elseif ($request->has('is_active')) {
                $disease->status = in_array($request->is_active, ['active', '1', 1, true, 'true'], true) ? 'active' : 'inactive';
            }

            if ($request->has('chemical_treatments')) {
                $disease->chemical_treatments = is_string($request->chemical_treatments) ? json_decode($request->chemical_treatments, true) : $request->chemical_treatments;
            }
            if ($request->has('organic_treatments')) {
                $disease->organic_treatments = is_string($request->organic_treatments) ? json_decode($request->organic_treatments, true) : $request->organic_treatments;
            }

            if ($request->hasFile('image')) {
                if ($disease->image_path && Storage::disk('public')->exists($disease->image_path)) {
                    Storage::disk('public')->delete($disease->image_path);
                }
                $disease->image_path = $request->file('image')->store('diseases', 'public');
            }

            $disease->save();

            return response()->json([
                'success' => true,
                'disease' => $this->formatDisease($disease),
                'message' => 'Disease updated successfully!',
            ]);
        } catch (Exception $e) {
            Log::error('Admin update disease error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update disease.'], 500);
        }
    }

    public function destroyDisease(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $disease = Disease::find($id);
        if (!$disease) {
            return response()->json(['success' => false, 'message' => 'Disease not found.'], 404);
        }

        try {
            if ($disease->image_path && Storage::disk('public')->exists($disease->image_path)) {
                Storage::disk('public')->delete($disease->image_path);
            }
            $disease->delete();

            return response()->json(['success' => true, 'message' => 'Disease removed successfully.']);
        } catch (Exception $e) {
            Log::error('Admin delete disease error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete disease.'], 500);
        }
    }

    public function toggleDiseaseStatus(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $disease = Disease::find($id);
        if (!$disease) return response()->json(['success' => false, 'message' => 'Disease not found.'], 404);

        $disease->status = ($disease->status === 'active') ? 'inactive' : 'active';
        $disease->save();

        return response()->json([
            'success' => true,
            'disease' => $this->formatDisease($disease),
            'message' => "Disease status changed to {$disease->status}.",
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * 4. DETECTION RECORDS / DETECTION LOGS
     * ══════════════════════════════════════════════════════════════════ */
    public function getScans(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        try {
            $query = RiceScan::with('user')->latest();

            if ($request->filled('disease')) {
                $dis = $request->disease;
                $query->where(function($q) use ($dis) {
                    $q->where('disease_name', 'like', "%{$dis}%");
                });
            }

            if ($request->filled('severity')) {
                $query->where('severity', $request->severity);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('disease_name', 'like', "%{$search}%")
                      ->orWhere('scientific_name', 'like', "%{$search}%")
                      ->orWhereHas('user', function($uq) use ($search) {
                          $uq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            $scans = $query->get()->map(fn($s) => $this->formatScan($s));

            return response()->json([
                'success' => true,
                'data' => [
                    'scans' => $scans,
                ],
                'total' => $scans->count(),
            ]);
        } catch (Exception $e) {
            Log::error('Admin scans fetch error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load detection records.'], 500);
        }
    }

    public function destroyScan(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $scan = RiceScan::find($id);
        if (!$scan) {
            return response()->json(['success' => false, 'message' => 'Detection record not found.'], 404);
        }

        try {
            if ($scan->image_path && !str_starts_with($scan->image_path, 'http') && Storage::disk('public')->exists($scan->image_path)) {
                Storage::disk('public')->delete($scan->image_path);
            }
            $scan->delete();

            return response()->json(['success' => true, 'message' => 'Detection log deleted successfully.']);
        } catch (Exception $e) {
            Log::error('Admin delete scan error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete detection log.'], 500);
        }
    }

    /* ══════════════════════════════════════════════════════════════════
     * 5. REPORTS & ANALYTICS
     * ══════════════════════════════════════════════════════════════════ */
    public function getReports(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        try {
            $period = $request->query('period', 'monthly');
            $totalScans = RiceScan::count();

            $diseases = [
                'Rice Leaf Blast' => RiceScan::where('disease_name', 'like', '%Blast%')->count(),
                'Bacterial Leaf Blight' => RiceScan::where('disease_name', 'like', '%Bacterial%')->count(),
                'Brown Spot' => RiceScan::where('disease_name', 'like', '%Brown Spot%')->count(),
                'Rice Tungro Disease' => RiceScan::where('disease_name', 'like', '%Tungro%')->count(),
                'Healthy Rice Leaf' => RiceScan::where('disease_name', 'like', '%Healthy%')->orWhere('severity', 'healthy')->count(),
            ];

            $severities = [
                'Healthy' => RiceScan::where('severity', 'healthy')->count(),
                'Mild (≤ 25%)' => RiceScan::where('severity', 'mild')->count(),
                'Moderate (26%-60%)' => RiceScan::where('severity', 'moderate')->count(),
                'Severe (> 60%)' => RiceScan::where('severity', 'severe')->count(),
            ];

            $trendData = [];
            if ($period === 'daily') {
                for ($i = 6; $i >= 0; $i--) {
                    $d = Carbon::today()->subDays($i);
                    $trendData[] = [
                        'label' => $d->format('M j (D)'),
                        'count' => RiceScan::whereDate('created_at', $d)->count(),
                    ];
                }
            } elseif ($period === 'weekly') {
                for ($i = 5; $i >= 0; $i--) {
                    $start = Carbon::now()->subWeeks($i)->startOfWeek();
                    $end = Carbon::now()->subWeeks($i)->endOfWeek();
                    $trendData[] = [
                        'label' => 'Wk ' . $start->format('M d') . ' - ' . $end->format('M d'),
                        'count' => RiceScan::whereBetween('created_at', [$start, $end])->count(),
                    ];
                }
            } else {
                for ($i = 5; $i >= 0; $i--) {
                    $m = Carbon::now()->subMonths($i);
                    $trendData[] = [
                        'label' => $m->format('M Y'),
                        'count' => RiceScan::whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count(),
                    ];
                }
            }

            $diseaseBreakdown = [];
            foreach ($diseases as $dName => $dCount) {
                $pct = $totalScans > 0 ? round(($dCount / $totalScans) * 100, 1) : 0;
                $diseaseBreakdown[] = [
                    'name' => $dName,
                    'count' => $dCount,
                    'percent' => $pct,
                ];
            }

            $timeline = array_map(function($t) {
                return [
                    'label' => $t['label'],
                    'total' => $t['count'],
                    'count' => $t['count'],
                ];
            }, $trendData);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_detections' => $totalScans,
                    'period' => $period,
                    'disease_frequency' => $diseases,
                    'disease_breakdown' => $diseaseBreakdown,
                    'severity_breakdown' => $severities,
                    'trends' => $trendData,
                    'timeline' => $timeline,
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Admin reports error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to generate reports.'], 500);
        }
    }

    public function exportScansCsv(Request $request)
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $scans = RiceScan::with('user')->latest()->get();

        $csvHeader = ['ID', 'Farmer Name', 'Email', 'Location', 'Detected Disease', 'Scientific Name', 'Confidence %', 'Severity', 'Notes / Advisory', 'Date & Time'];
        $rows = [];
        $rows[] = implode(',', $csvHeader);

        foreach ($scans as $s) {
            $rows[] = implode(',', [
                $s->id,
                '"' . str_replace('"', '""', $s->user ? $s->user->name : 'Walk-in Farmer') . '"',
                '"' . str_replace('"', '""', $s->user ? $s->user->email : 'N/A') . '"',
                '"' . str_replace('"', '""', $s->user ? ($s->user->location ?: 'Roxas, Oriental Mindoro') : 'Roxas, Oriental Mindoro') . '"',
                '"' . str_replace('"', '""', $s->disease_name) . '"',
                '"' . str_replace('"', '""', $s->scientific_name ?: 'Oryza sativa') . '"',
                $s->confidence . '%',
                ucfirst($s->severity),
                '"' . str_replace('"', '""', $s->notes ?: '') . '"',
                '"' . ($s->created_at ? $s->created_at->format('Y-m-d H:i:s') : '') . '"',
            ]);
        }

        $csvContent = implode("\n", $rows);
        $fileName = 'oryzatix_detection_logs_' . date('Y_m_d_His') . '.csv';

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * 6. CHATBOT MANAGEMENT (Conversations & Knowledge FAQ CRUD)
     * ══════════════════════════════════════════════════════════════════ */
    public function getChatConversations(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        try {
            $conversations = ChatMessage::with('user')
                ->latest()
                ->take(100)
                ->get()
                ->map(fn($c) => [
                    'id' => $c->id,
                    'user_name' => $c->user ? $c->user->name : 'Guest Farmer',
                    'user_email' => $c->user ? $c->user->email : 'N/A',
                    'role' => $c->role,
                    'content' => $c->content,
                    'language' => $c->language,
                    'time' => $c->created_at ? $c->created_at->format('M j, Y g:i A') : 'N/A',
                    'time_ago' => $c->created_at ? $c->created_at->diffForHumans() : '',
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'conversations' => $conversations,
                ],
                'total' => $conversations->count(),
            ]);
        } catch (Exception $e) {
            Log::error('Admin chat logs error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load conversation logs.'], 500);
        }
    }

    public function getChatbotKnowledge(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        try {
            $knowledge = ChatbotKnowledge::latest()->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'knowledge' => $knowledge,
                ],
                'total' => $knowledge->count(),
            ]);
        } catch (Exception $e) {
            Log::error('Admin chatbot knowledge error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load FAQ knowledge.'], 500);
        }
    }

    public function storeChatbotKnowledge(Request $request): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'category' => 'nullable|string|max:100',
            'language' => 'nullable|string|in:tagalog,english,all',
            'status' => 'required|in:active,inactive',
        ]);

        try {
            $item = ChatbotKnowledge::create([
                'question' => $request->question,
                'answer' => $request->answer,
                'category' => $request->category ?: 'General',
                'language' => $request->language ?: 'all',
                'status' => $request->status,
            ]);

            return response()->json([
                'success' => true,
                'item' => $item,
                'message' => 'FAQ / Knowledge entry added successfully!',
            ], 201);
        } catch (Exception $e) {
            Log::error('Admin store knowledge error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to add FAQ knowledge.'], 500);
        }
    }

    public function updateChatbotKnowledge(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $item = ChatbotKnowledge::find($id);
        if (!$item) return response()->json(['success' => false, 'message' => 'Knowledge item not found.'], 404);

        $request->validate([
            'question' => 'sometimes|string|max:500',
            'answer' => 'sometimes|string',
            'category' => 'nullable|string|max:100',
            'language' => 'nullable|string|in:tagalog,english,all',
            'status' => 'sometimes|in:active,inactive',
        ]);

        try {
            if ($request->filled('question')) $item->question = $request->question;
            if ($request->filled('answer')) $item->answer = $request->answer;
            if ($request->has('category')) $item->category = $request->category;
            if ($request->has('language')) $item->language = $request->language;
            if ($request->filled('status')) $item->status = $request->status;

            $item->save();

            return response()->json([
                'success' => true,
                'item' => $item,
                'message' => 'FAQ knowledge updated successfully!',
            ]);
        } catch (Exception $e) {
            Log::error('Admin update knowledge error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update FAQ knowledge.'], 500);
        }
    }

    public function destroyChatbotKnowledge(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $item = ChatbotKnowledge::find($id);
        if (!$item) return response()->json(['success' => false, 'message' => 'Item not found.'], 404);

        try {
            $item->delete();
            return response()->json(['success' => true, 'message' => 'FAQ item deleted successfully.']);
        } catch (Exception $e) {
            Log::error('Admin delete knowledge error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete item.'], 500);
        }
    }

    public function toggleChatbotKnowledgeStatus(Request $request, int $id): JsonResponse
    {
        if ($res = $this->checkAdmin($request)) return $res;

        $item = ChatbotKnowledge::find($id);
        if (!$item) return response()->json(['success' => false, 'message' => 'Item not found.'], 404);

        $item->status = ($item->status === 'active') ? 'inactive' : 'active';
        $item->save();

        return response()->json([
            'success' => true,
            'item' => $item,
            'message' => "FAQ status changed to {$item->status}.",
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
            'scans_count' => $user->scans_count ?? $user->scans()->count(),
            'created_at' => $user->created_at ? $user->created_at->format('M j, Y g:i A') : 'N/A',
        ];
    }

    private function formatScan(RiceScan $scan): array
    {
        $imageUrl = null;
        if ($scan->image_path) {
            if (str_starts_with($scan->image_path, 'data:') || str_starts_with($scan->image_path, 'http://') || str_starts_with($scan->image_path, 'https://')) {
                $imageUrl = $scan->image_path;
            } else {
                $imageUrl = Storage::disk('public')->url($scan->image_path);
            }
        }

        return [
            'id' => $scan->id,
            'user_id' => $scan->user_id,
            'farmer_name' => $scan->user ? $scan->user->name : 'Walk-in Farmer',
            'farmer_email' => $scan->user ? $scan->user->email : 'N/A',
            'farmer_role' => $scan->user ? $scan->user->role : 'farmer',
            'location' => $scan->user ? ($scan->user->location ?: 'Roxas, Oriental Mindoro') : 'Roxas, Oriental Mindoro',
            'image_url' => $imageUrl,
            'disease_name' => $scan->disease_name ?: 'Healthy Rice Leaf',
            'scientific_name' => $scan->scientific_name ?: 'Oryza sativa',
            'confidence' => (float) $scan->confidence,
            'severity' => strtolower($scan->severity ?: 'healthy'),
            'notes' => $scan->notes ?: '',
            'has_advisory' => !empty($scan->notes),
            'created_at' => $scan->created_at ? $scan->created_at->format('M j, Y g:i A') : 'N/A',
            'time_ago' => $scan->created_at ? $scan->created_at->diffForHumans() : '',
        ];
    }

    private function formatDisease(Disease $disease): array
    {
        $imageUrl = null;
        if ($disease->image_path) {
            if (str_starts_with($disease->image_path, 'http://') || str_starts_with($disease->image_path, 'https://') || str_starts_with($disease->image_path, 'data:')) {
                $imageUrl = $disease->image_path;
            } elseif (str_starts_with($disease->image_path, 'images/')) {
                $imageUrl = asset($disease->image_path);
            } else {
                $fn = basename($disease->image_path);
                $code = strtolower($disease->code ?: 'blast');
                if (file_exists(public_path('images/diseases/' . $fn))) {
                    $imageUrl = asset('images/diseases/' . $fn);
                } elseif (file_exists(public_path("images/diseases/{$code}.webp"))) {
                    $imageUrl = asset("images/diseases/{$code}.webp");
                } elseif (file_exists(public_path("images/diseases/{$code}.jpg"))) {
                    $imageUrl = asset("images/diseases/{$code}.jpg");
                } else {
                    $imageUrl = asset('storage/' . $disease->image_path);
                }
            }
        } else {
            $code = strtolower($disease->code ?: 'blast');
            $ext = ($code === 'tungro' || $code === 'healthy' || $code === 'sheath_blight') ? 'jpg' : 'webp';
            $imageUrl = asset("images/diseases/{$code}.{$ext}");
        }

        return [
            'id' => $disease->id,
            'name' => $disease->name,
            'code' => $disease->code,
            'scientific_name' => $disease->scientific_name,
            'image_path' => $disease->image_path,
            'image_url' => $imageUrl,
            'description' => $disease->description,
            'symptoms' => $disease->symptoms,
            'causes' => $disease->causes,
            'prevention' => $disease->prevention,
            'recommended_treatment' => $disease->recommended_treatment,
            'treatment' => $disease->recommended_treatment,
            'chemical_treatments' => $disease->chemical_treatments,
            'organic_treatments' => $disease->organic_treatments,
            'status' => $disease->status,
            'is_active' => $disease->status === 'active',
            'created_at' => $disease->created_at ? $disease->created_at->format('M j, Y') : 'N/A',
        ];
    }

    /**
     * 7. Security Settings (Login Attempts & Lockout Duration)
     */
    public function getSecuritySettings(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $maxAttempts = (int) SystemSetting::get('max_login_attempts', 3);
        $lockoutSeconds = (int) SystemSetting::get('lockout_duration_seconds', 30);

        return response()->json([
            'success' => true,
            'settings' => [
                'max_login_attempts' => $maxAttempts,
                'lockout_duration_seconds' => $lockoutSeconds,
                'preset_attempts' => [3, 5, 10],
                'preset_durations' => [
                    ['seconds' => 30, 'label' => '30 Seconds (Default)'],
                    ['seconds' => 60, 'label' => '1 Minute (60 Seconds)'],
                    ['seconds' => 120, 'label' => '2 Minutes (120 Seconds)'],
                    ['seconds' => 300, 'label' => '5 Minutes (300 Seconds)'],
                ],
            ],
            'message' => 'Security settings loaded successfully.',
        ]);
    }

    public function updateSecuritySettings(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $validated = $request->validate([
            'max_login_attempts' => 'required|integer|min:1|max:20',
            'lockout_duration_seconds' => 'required|integer|min:5|max:3600',
        ]);

        SystemSetting::set(
            'max_login_attempts',
            $validated['max_login_attempts'],
            'integer',
            'Maximum Failed Login Attempts',
            'security',
            'Number of consecutive incorrect password attempts before the account is temporarily locked out.'
        );

        SystemSetting::set(
            'lockout_duration_seconds',
            $validated['lockout_duration_seconds'],
            'integer',
            'Lockout Penalty Duration (Seconds)',
            'security',
            'Duration in seconds the user must wait before attempting to sign in again.'
        );

        Cache::forget('system_setting_max_login_attempts');
        Cache::forget('system_setting_lockout_duration_seconds');

        return response()->json([
            'success' => true,
            'settings' => [
                'max_login_attempts' => (int) $validated['max_login_attempts'],
                'lockout_duration_seconds' => (int) $validated['lockout_duration_seconds'],
            ],
            'message' => 'Login Security Settings updated successfully! The new limit is ' . $validated['max_login_attempts'] . ' attempts and ' . $validated['lockout_duration_seconds'] . ' seconds base penalty.',
        ]);
    }

    public function resetLockouts(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'Lahat ng kasalukuyang naka-lockout na IP at account ay matagumpay na na-reset.',
        ]);
    }
}
