<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $rating = $request->query('rating');

        $query = Review::query()
            ->with(['product:id,name,slug']);

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        if ($rating !== null && $rating !== '') {
            $ratingInt = (int) $rating;
            if ($ratingInt >= 1 && $ratingInt <= 5) {
                $query->where('rating', $ratingInt);
            }
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('author_name', 'like', $like)
                    ->orWhere('author_email', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('comment', 'like', $like)
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $like));
            });
        }

        $reviews = $query->latest()->paginate($perPage);

        return response()->json($reviews);
    }

    public function moderate(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $review->update([
            'status' => $validated['status'],
            'moderated_at' => now(),
        ]);

        $review->load('product:id,name,slug');

        return response()->json([
            'message' => 'Review moderation status updated.',
            'review' => $review,
        ]);
    }

    public function destroy(Review $review): JsonResponse
    {
        $review->delete();

        return response()->json([
            'message' => 'Review deleted.',
        ]);
    }
}
