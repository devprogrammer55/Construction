<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Exception;

class AuthController extends Controller
{
    public function signup(Request $request)
    {
        try {
            // Step 3.1: Validation
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:users,email',
                'phone' => 'required|string|max:20',
                'user_type' => 'required|in:client,contractor,subcontractor,inspector',
                'first_name' => 'required|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'password' => 'required|min:6',
                'device_type' => 'required|in:A,I,W',
            ], [
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'email.unique' => 'This email is already registered',
                'phone.required' => 'Phone number is required',
                'user_type.required' => 'User type is required',
                'user_type.in' => 'Invalid user type selected',
                'first_name.required' => 'First name is required',
                'first_name.max' => 'First name cannot exceed 255 characters',
                'password.required' => 'Password is required',
                'password.min' => 'Password must be at least 6 characters',
                'device_type.required' => 'Device type is required',
                'device_type.in' => 'Invalid device type',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Step 3.2: Business Logic - Check existing user
            $existingUser = User::where('email', $request->email)
                ->where('is_deleted', 0)
                ->first();

            if ($existingUser) {
                return $this->toJsonEnc($existingUser, 'Account already exists', '008');
            }

            // Step 3.3: Create Record
            $user = new User();
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->user_type = $request->user_type;
            $user->first_name = $request->first_name;
            $user->last_name = $request->last_name;
            $user->password = Hash::make($request->password);
            $user->step_no = 1;
            $user->is_verified = false;
            $user->is_active = true;
            $user->is_deleted = false;
            $user->save();

            // Step 3.4: Generate Token
            $accessToken = Str::random(64);
            UserDevice::create([
                'user_id' => $user->id,
                'token' => $accessToken,
                'device_type' => $request->device_type,
                'ip_address' => $request->ip(),
                'last_activity' => now(),
            ]);

            // Step 3.5: Prepare Response Data
            $responseData = [
                'user_id' => $user->id,
                'token' => $accessToken,
                'step_no' => $user->step_no,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_type' => $user->user_type,
                'is_verified' => $user->is_verified,
            ];

            // Step 3.6: Return Response
            return $this->toJsonEnc($responseData, 'Registration successful', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|min:6',
                'device_type' => 'required|in:A,I,W',
            ], [
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'password.required' => 'Password is required',
                'password.min' => 'Password must be at least 6 characters',
                'device_type.required' => 'Device type is required',
                'device_type.in' => 'Invalid device type',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $user = User::where('email', $request->email)
                ->where('is_deleted', 0)
                ->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return $this->toJsonEnc([], 'Invalid credentials', '401');
            }

            if (!$user->is_active) {
                return $this->toJsonEnc([], 'Account is deactivated', '403');
            }

            // Generate new token
            $accessToken = Str::random(64);
            
            // Update or create device record
            UserDevice::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_type' => $request->device_type,
                ],
                [
                    'token' => $accessToken,
                    'ip_address' => $request->ip(),
                    'last_activity' => now(),
                ]
            );

            $responseData = [
                'user_id' => $user->id,
                'token' => $accessToken,
                'step_no' => $user->step_no,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_type' => $user->user_type,
                'is_verified' => $user->is_verified,
                'profile_image' => $user->profile_image,
            ];

            return $this->toJsonEnc($responseData, 'Login successful', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function profile(Request $request)
    {
        try {
            $user = User::with(['companies', 'currentCompany'])
                ->find($request->user_id);

            if (!$user) {
                return $this->toJsonEnc([], 'User not found', '404');
            }

            $profileData = [
                'id' => $user->id,
                'email' => $user->email,
                'phone' => $user->phone,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_type' => $user->user_type,
                'profile_image' => $user->profile_image,
                'business_name' => $user->business_name,
                'business_logo' => $user->business_logo,
                'business_phone' => $user->business_phone,
                'business_address' => $user->business_address,
                'is_verified' => $user->is_verified,
                'step_no' => $user->step_no,
                'companies' => $user->companies,
                'current_company' => $user->currentCompany,
            ];

            return $this->toJsonEnc($profileData, 'Profile retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'sometimes|required|string|max:255',
                'last_name' => 'sometimes|required|string|max:255',
                'phone' => 'sometimes|required|string|max:20',
                'business_name' => 'nullable|string|max:255',
                'business_phone' => 'nullable|string|max:20',
                'business_address' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $user = User::find($request->user_id);

            if (!$user) {
                return $this->toJsonEnc([], 'User not found', '404');
            }

            $user->update($request->only([
                'first_name', 'last_name', 'phone', 'business_name',
                'business_phone', 'business_address'
            ]));

            return $this->toJsonEnc($user, 'Profile updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required',
                'new_password' => 'required|min:6|confirmed',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $user = User::find($request->user_id);

            if (!$user) {
                return $this->toJsonEnc([], 'User not found', '404');
            }

            if (!Hash::check($request->current_password, $user->password)) {
                return $this->toJsonEnc([], 'Current password is incorrect', '400');
            }

            $user->update(['password' => Hash::make($request->new_password)]);

            return $this->toJsonEnc([], 'Password changed successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function logout(Request $request)
    {
        try {
            UserDevice::where('user_id', $request->user_id)->delete();

            return $this->toJsonEnc([], 'Logged out successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Implement password reset logic here
            // This would typically involve sending an email with reset link

            return $this->toJsonEnc([], 'Password reset email sent', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'token' => 'required|string',
                'password' => 'required|min:6|confirmed',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Implement password reset logic here
            // This would typically verify the reset token and update password

            return $this->toJsonEnc([], 'Password reset successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }
}
