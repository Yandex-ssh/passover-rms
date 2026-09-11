<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetStaffPasswordRequest;
use App\Http\Requests\StoreStaffUserRequest;
use App\Http\Requests\UpdateStaffUserRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffUserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StaffUserResource::collection(User::query()->orderBy('name')->get())
            ->additional(['success' => true]);
    }

    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        $data = $request->safe()->only(['name', 'email', 'password', 'role', 'is_active']);
        $data['is_active'] = $data['is_active'] ?? true;
        $user = User::create($data);

        return (new StaffUserResource($user))->additional(['success' => true])->response()->setStatusCode(201);
    }

    public function update(UpdateStaffUserRequest $request, User $user): StaffUserResource
    {
        $changes = $request->safe()->only(['name', 'email', 'role', 'is_active']);
        $this->guardRoleAndStatusChange($request->user(), $user, $changes);
        $user->fill($changes)->save();

        return (new StaffUserResource($user))->additional(['success' => true]);
    }

    public function activate(User $user): StaffUserResource
    {
        $user->forceFill(['is_active' => true])->save();

        return (new StaffUserResource($user))->additional(['success' => true]);
    }

    public function deactivate(User $user): StaffUserResource
    {
        $this->guardRoleAndStatusChange(request()->user(), $user, ['is_active' => false]);
        $user->forceFill(['is_active' => false])->save();

        return (new StaffUserResource($user))->additional(['success' => true]);
    }

    public function password(ResetStaffPasswordRequest $request, User $user): StaffUserResource
    {
        $user->forceFill(['password' => $request->validated('password')])->save();

        return (new StaffUserResource($user))->additional(['success' => true]);
    }

    public function destroy(User $user): JsonResponse
    {
        $actor = request()->user();

        if ($actor->is($user)) {
            abort(422, 'You cannot delete your own account.');
        }

        if ($user->role === 'admin' && $user->is_active
            && User::query()->where('role', 'admin')->where('is_active', true)->count() <= 1) {
            abort(422, 'At least one active Admin account must remain.');
        }

        if (Order::query()->where('confirmed_by', $user->id)->exists()
            || Payment::query()->where('processed_by', $user->id)->exists()) {
            abort(422, 'This staff account is referenced by order or payment history. Deactivate it instead.');
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Staff account deleted successfully.',
        ]);
    }

    private function guardRoleAndStatusChange(User $actor, User $target, array $changes): void
    {
        $willBeAdmin = array_key_exists('role', $changes) ? $changes['role'] === 'admin' : $target->role === 'admin';
        $willBeActive = array_key_exists('is_active', $changes) ? (bool) $changes['is_active'] : $target->is_active;

        if ($actor->is($target) && $target->role === 'admin' && (! $willBeAdmin || ! $willBeActive)) {
            abort(422, 'You cannot demote or deactivate your own Admin account.');
        }

        if ($target->role === 'admin' && $target->is_active && (! $willBeAdmin || ! $willBeActive)
            && User::query()->where('role', 'admin')->where('is_active', true)->count() <= 1) {
            abort(422, 'At least one active Admin account must remain.');
        }
    }
}
