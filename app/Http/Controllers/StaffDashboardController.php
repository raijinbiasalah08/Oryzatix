<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\RiceScan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class StaffDashboardController extends Controller
{
    /**
     * Get staff dashboard metrics, recent field scans, and barangay outbreak data.
     */
    public function overview(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (!$currentUser || !in_array($currentUser->role, ['agri_worker', 'admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Agricultural extension worker or admin access required.',
            ], 403);
        }

        try {
            $totalScans = RiceScan::count();
            $totalFarmers = User::where('role', 'farmer')->count();

            // Disease breakdown
            $blbCount = RiceScan::where('disease_name', 'like', '%Bacterial%')->orWhere('disease_name', 'blb')->count();
            $blastCount = RiceScan::where('disease_name', 'like', '%Blast%')->orWhere('disease_name', 'blast')->count();
            $brownSpotCount = RiceScan::where('disease_name', 'like', '%Brown Spot%')->orWhere('disease_name', 'brown_spot')->count();
            $tungroCount = RiceScan::where('disease_name', 'like', '%Tungro%')->orWhere('disease_name', 'tungro')->count();
            $sheathBlightCount = RiceScan::where('disease_name', 'like', '%Sheath Blight%')->orWhere('disease_name', 'sheath_blight')->count();
            $healthyCount = RiceScan::where('disease_name', 'like', '%Healthy%')->orWhere('severity', 'healthy')->count();

            $severityReviewsNeeded = RiceScan::where('severity', '!=', 'healthy')->count();
            $healthyRatio = $totalScans > 0 ? round(($healthyCount / $totalScans) * 100, 1) : 0.0;

            // Fetch recent scans with user info
            $recentScans = RiceScan::with('user')
                ->latest()
                ->take(50)
                ->get()
                ->map(function ($scan) {
                    $imageUrl = (new \App\Http\Controllers\RiceScanController)->resolveScanImageUrl($scan->image_path, $scan->disease_name);

                    return [
                        'id' => $scan->id,
                        'farmer_name' => $scan->user ? $scan->user->name : 'Walk-in Farmer',
                        'farmer_email' => $scan->user ? $scan->user->email : 'N/A',
                        'location' => $scan->user ? ($scan->user->location ?: 'Roxas, Oriental Mindoro') : 'Roxas, Oriental Mindoro',
                        'image_url' => $imageUrl,
                        'disease_name' => $scan->disease_name ?: 'Healthy Rice Leaf',
                        'scientific_name' => $scan->scientific_name ?: 'Oryza sativa',
                        'confidence' => (float) $scan->confidence,
                        'severity' => $scan->severity === 'healthy' ? 'healthy' : 'unassessed',
                        'severity_status' => $scan->severity === 'healthy' ? 'not_applicable' : 'unassessed',
                        'notes' => $scan->notes ?: '',
                        'created_at' => $scan->created_at ? $scan->created_at->format('M j, Y g:i A') : 'N/A',
                        'time_ago' => $scan->created_at ? $scan->created_at->diffForHumans() : '',
                    ];
                });

            // Aggregate surveillance by Barangay / Location
            $scansWithUsers = RiceScan::with('user')->get();
            $locationMap = [];

            foreach ($scansWithUsers as $scan) {
                $loc = $scan->user && $scan->user->location ? trim($scan->user->location) : 'Roxas, Oriental Mindoro';
                if (!isset($locationMap[$loc])) {
                    $locationMap[$loc] = [
                        'location' => $loc,
                        'total_scans' => 0,
                        'blb' => 0,
                        'blast' => 0,
                        'brown_spot' => 0,
                        'tungro' => 0,
                        'sheath_blight' => 0,
                        'healthy' => 0,
                        'other' => 0,
                        'severe' => 0,
                        'moderate' => 0,
                        'needs_assessment' => 0,
                    ];
                }

                $locationMap[$loc]['total_scans']++;
                $sev = strtolower($scan->severity ?: 'unassessed');
                if ($sev !== 'healthy') $locationMap[$loc]['needs_assessment']++;

                $dName = strtolower($scan->disease_name ?: '');
                if (str_contains($dName, 'sheath')) {
                    $locationMap[$loc]['sheath_blight']++;
                } elseif (str_contains($dName, 'blight') || str_contains($dName, 'blb')) {
                    $locationMap[$loc]['blb']++;
                } elseif (str_contains($dName, 'blast')) {
                    $locationMap[$loc]['blast']++;
                } elseif (str_contains($dName, 'brown')) {
                    $locationMap[$loc]['brown_spot']++;
                } elseif (str_contains($dName, 'tungro')) {
                    $locationMap[$loc]['tungro']++;
                } elseif (str_contains($dName, 'healthy') || $sev === 'healthy') {
                    $locationMap[$loc]['healthy']++;
                } else {
                    $locationMap[$loc]['other']++;
                }
            }

            $locationSurveillance = [];
            foreach ($locationMap as $loc => $data) {
                $status = $data['needs_assessment'] > 0 ? 'review_required' : 'normal';
                $data['status'] = $status;
                $locationSurveillance[] = $data;
            }

            usort($locationSurveillance, fn($a, $b) => $b['total_scans'] <=> $a['total_scans']);

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => [
                        'total_field_scans' => $totalScans,
                        'severity_reviews_needed' => $severityReviewsNeeded,
                        'total_farmers' => $totalFarmers,
                        'healthy_ratio' => $healthyRatio,
                    ],
                    'disease_counts' => [
                        'blb' => $blbCount,
                        'blast' => $blastCount,
                        'brown_spot' => $brownSpotCount,
                        'tungro' => $tungroCount,
                        'sheath_blight' => $sheathBlightCount,
                        'healthy' => $healthyCount,
                    ],
                    'recent_scans' => $recentScans,
                    'location_surveillance' => $locationSurveillance,
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Staff dashboard error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load staff dashboard data.',
            ], 500);
        }
    }

    /**
     * Add or update agronomist / staff advisory notes for a specific farmer scan.
     */
    public function addAdvisory(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (!$currentUser || !in_array($currentUser->role, ['agri_worker', 'admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Extension worker access required.',
            ], 403);
        }

        $request->validate([
            'advisory' => 'required|string|max:1000',
        ]);

        try {
            $scan = RiceScan::findOrFail($id);
            $staffName = $currentUser->name ?: 'Staff Agronomist';
            $timestamp = now()->format('M j, Y g:i A');

            $advisoryHeader = "[DA Extension Note by {$staffName} on {$timestamp}]: ";
            $scan->notes = $advisoryHeader . trim($request->advisory);
            $scan->save();

            return response()->json([
                'success' => true,
                'message' => 'Staff advisory saved successfully!',
                'data' => [
                    'scan_id' => $scan->id,
                    'notes' => $scan->notes,
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Staff advisory save failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save staff advisory.',
            ], 500);
        }
    }
}
