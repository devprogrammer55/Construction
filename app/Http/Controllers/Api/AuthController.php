<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use App\Models\UserCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function registerStep1(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:20',
            'role' => 'required|string|in:contractor,client,subcontractor',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role,
            'registration_step' => 1,
            'is_active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User details saved successfully',
            'user_id' => $user->id,
            'next_step' => 2
        ]);
    }

    public function registerStep2(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'company_name' => 'required|string|max:255',
            'company_type' => 'required|string|max:255',
            'company_address' => 'required|string|max:500',
            'company_phone' => 'required|string|max:20',
            'company_email' => 'required|string|email|max:255',
            'company_website' => 'nullable|string|max:255',
            'company_logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'tax_id' => 'nullable|string|max:50',
            'license_number' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::findOrFail($request->user_id);

        if ($user->registration_step != 1) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid registration step'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $company = Company::create([
                'name' => $request->company_name,
                'code' => Company::generateCode(),
                'type' => $request->company_type,
                'address' => $request->company_address,
                'phone' => $request->company_phone,
                'email' => $request->company_email,
                'website' => $request->company_website,
                'tax_id' => $request->tax_id,
                'license_number' => $request->license_number,
                'status' => 'active',
                'settings' => [],
            ]);

            if ($request->hasFile('company_logo')) {
                $logoPath = $request->file('company_logo')->store('company_logos', 'public');
                $company->update(['logo' => $logoPath]);
            }

            UserCompany::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => 'admin',
                'status' => 'active',
                'permissions' => ['all'],
            ]);

            $user->update([
                'registration_step' => 2,
                'current_company_id' => $company->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Company details saved successfully',
                'user_id' => $user->id,
                'company_id' => $company->id,
                'next_step' => 3
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to save company details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function registerStep3(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'team_members' => 'required|array',
            'team_members.*.name' => 'required|string|max:255',
            'team_members.*.email' => 'required|string|email|max:255',
            'team_members.*.role' => 'required|string|in:manager,supervisor,worker',
            'team_members.*.phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::findOrFail($request->user_id);

        if ($user->registration_step != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid registration step'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $company = $user->currentCompany;

            foreach ($request->team_members as $member) {
                $existingUser = User::where('email', $member['email'])->first();
                
                if (!$existingUser) {
                    $tempPassword = Str::random(12);
                    $existingUser = User::create([
                        'name' => $member['name'],
                        'email' => $member['email'],
                        'password' => Hash::make($tempPassword),
                        'phone' => $member['phone'] ?? null,
                        'role' => $member['role'],
                        'is_active' => false,
                        'registration_step' => 3,
                    ]);
                }

                UserCompany::create([
                    'user_id' => $existingUser->id,
                    'company_id' => $company->id,
                    'role' => $member['role'],
                    'status' => 'pending',
                    'permissions' => $this->getRolePermissions($member['role']),
                ]);

                // Send invitation email
                $this->sendInvitationEmail($existingUser, $company);
            }

            $user->update([
                'registration_step' => 3,
                'is_active' => true,
            ]);

            DB::commit();

            $token = $user->createToken('API Token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registration completed successfully',
                'user' => $user->load('companies'),
                'token' => $token,
                'registration_step' => 3
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete registration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is not active. Please check your email for activation.'
            ], 403);
        }

        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => $user->load('companies'),
            'token' => $token,
            'registration_step' => $user->registration_step
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token
        ]);
    }

    public function profile(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load(['companies', 'currentCompany'])
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'avatar' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $user->update($request->only(['name', 'phone']));

        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $user->update(['avatar' => $avatarPath]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user->fresh()
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 400);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unable to send reset link'
        ], 400);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|string|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid token or email'
        ], 400);
    }

    private function getRolePermissions($role)
    {
        $permissions = [
            'admin' => ['all'],
            'manager' => ['read', 'write', 'update'],
            'supervisor' => ['read', 'write'],
            'worker' => ['read'],
            'client' => ['read'],
            'subcontractor' => ['read', 'write'],
        ];

        return $permissions[$role] ?? ['read'];
    }

    private function sendInvitationEmail($user, $company)
    {
        // Implementation depends on your mail setup
        // This is a placeholder for email invitation logic
    }
}
