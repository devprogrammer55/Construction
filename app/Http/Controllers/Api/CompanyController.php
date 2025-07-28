<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\UserCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Exception;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        try {
            $companies = Company::where('is_deleted', 0)
                ->whereHas('userCompanies', function ($query) use ($request) {
                    $query->where('user_id', $request->user_id);
                })
                ->with(['userCompanies' => function ($query) {
                    $query->where('status', 'active');
                }])
                ->get();

            return $this->toJsonEnc($companies, 'Companies retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function store(Request $request)
    {
        try {
            // Step 3.1: Validation
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:100',
                'address' => 'required|string|max:500',
                'phone' => 'required|string|max:20',
                'email' => 'required|email|max:255',
                'website' => 'nullable|string|max:255',
                'tax_id' => 'nullable|string|max:50',
                'license_number' => 'nullable|string|max:50',
                'description' => 'nullable|string|max:1000',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ], [
                'name.required' => 'Company name is required',
                'name.max' => 'Company name cannot exceed 255 characters',
                'type.required' => 'Company type is required',
                'type.max' => 'Company type cannot exceed 100 characters',
                'address.required' => 'Company address is required',
                'address.max' => 'Company address cannot exceed 500 characters',
                'phone.required' => 'Company phone is required',
                'phone.max' => 'Company phone cannot exceed 20 characters',
                'email.required' => 'Company email is required',
                'email.email' => 'Please provide a valid email address',
                'email.max' => 'Company email cannot exceed 255 characters',
                'website.max' => 'Website cannot exceed 255 characters',
                'tax_id.max' => 'Tax ID cannot exceed 50 characters',
                'license_number.max' => 'License number cannot exceed 50 characters',
                'description.max' => 'Description cannot exceed 1000 characters',
                'logo.image' => 'Logo must be an image file',
                'logo.mimes' => 'Logo must be in JPEG, PNG, or JPG format',
                'logo.max' => 'Logo file size cannot exceed 2MB',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Step 3.2: Check existing company
            $existingCompany = Company::where('email', $request->email)
                ->where('is_deleted', 0)
                ->first();

            if ($existingCompany) {
                return $this->toJsonEnc([], 'Company with this email already exists', '409');
            }

            // Step 3.3: Create company
            $company = new Company();
            $company->name = $request->name;
            $company->code = $this->generateCompanyCode();
            $company->type = $request->type;
            $company->address = $request->address;
            $company->phone = $request->phone;
            $company->email = $request->email;
            $company->website = $request->website;
            $company->tax_id = $request->tax_id;
            $company->license_number = $request->license_number;
            $company->description = $request->description;
            $company->status = 'active';
            $company->is_deleted = false;

            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('company_logos', 'public');
                $company->logo = $logoPath;
            }

            $company->save();

            // Step 3.4: Create user-company relationship
            UserCompany::create([
                'user_id' => $request->user_id,
                'company_id' => $company->id,
                'role' => 'admin',
                'status' => 'active',
                'permissions' => ['all'],
            ]);

            // Step 3.5: Return response
            return $this->toJsonEnc($company->load('userCompanies'), 'Company created successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $company = Company::with(['userCompanies', 'projects'])
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first();

            if (!$company) {
                return $this->toJsonEnc([], 'Company not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            return $this->toJsonEnc($company, 'Company retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'type' => 'sometimes|required|string|max:100',
                'address' => 'sometimes|required|string|max:500',
                'phone' => 'sometimes|required|string|max:20',
                'email' => 'sometimes|required|email|max:255',
                'website' => 'nullable|string|max:255',
                'tax_id' => 'nullable|string|max:50',
                'license_number' => 'nullable|string|max:50',
                'description' => 'nullable|string|max:1000',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'status' => 'sometimes|required|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $company = Company::find($id);

            if (!$company) {
                return $this->toJsonEnc([], 'Company not found', '404');
            }

            // Check if user has admin access to this company
            $userCompany = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $id)
                ->where('role', 'admin')
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->toJsonEnc([], 'Admin access required', '403');
            }

            // Check email uniqueness if changed
            if ($request->has('email') && $request->email !== $company->email) {
                $existingCompany = Company::where('email', $request->email)
                    ->where('id', '!=', $id)
                    ->where('is_deleted', 0)
                    ->first();

                if ($existingCompany) {
                    return $this->toJsonEnc([], 'Company with this email already exists', '409');
                }
            }

            $updateData = $request->only([
                'name', 'type', 'address', 'phone', 'email', 'website',
                'tax_id', 'license_number', 'description', 'status'
            ]);

            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('company_logos', 'public');
                $updateData['logo'] = $logoPath;
            }

            $company->update($updateData);

            return $this->toJsonEnc($company->load('userCompanies'), 'Company updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $company = Company::find($id);

            if (!$company) {
                return $this->toJsonEnc([], 'Company not found', '404');
            }

            // Check if user has admin access to this company
            $userCompany = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $id)
                ->where('role', 'admin')
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->toJsonEnc([], 'Admin access required', '403');
            }

            // Soft delete
            $company->update(['is_deleted' => true]);

            return $this->toJsonEnc([], 'Company deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function inviteMember(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'email' => 'required|email|max:255',
                'role' => 'required|in:admin,manager,supervisor,worker',
                'permissions' => 'nullable|array',
            ], [
                'company_id.required' => 'Company ID is required',
                'company_id.exists' => 'Invalid company selected',
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'email.max' => 'Email cannot exceed 255 characters',
                'role.required' => 'Role is required',
                'role.in' => 'Invalid role selected',
                'permissions.array' => 'Permissions must be an array',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Check if user has admin access to this company
            $userCompany = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $request->company_id)
                ->where('role', 'admin')
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->toJsonEnc([], 'Admin access required', '403');
            }

            // Check if member already exists
            $existingMember = UserCompany::where('company_id', $request->company_id)
                ->whereHas('user', function ($query) use ($request) {
                    $query->where('email', $request->email);
                })
                ->first();

            if ($existingMember) {
                return $this->toJsonEnc([], 'Member already exists in this company', '409');
            }

            // Create invitation
            $invitation = UserCompany::create([
                'user_id' => 0, // Will be updated when user accepts invitation
                'company_id' => $request->company_id,
                'role' => $request->role,
                'status' => 'pending',
                'permissions' => $request->permissions ?? $this->getDefaultPermissions($request->role),
            ]);

            // Here you would typically send invitation email
            // Mail::to($request->email)->send(new CompanyInvitation($invitation));

            return $this->toJsonEnc($invitation, 'Invitation sent successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function getMembers(Request $request, $companyId)
    {
        try {
            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $members = UserCompany::with(['user'])
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->get();

            return $this->toJsonEnc($members, 'Company members retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    private function generateCompanyCode()
    {
        $code = 'CMP' . strtoupper(Str::random(6));
        
        while (Company::where('code', $code)->exists()) {
            $code = 'CMP' . strtoupper(Str::random(6));
        }

        return $code;
    }

    private function getDefaultPermissions($role)
    {
        $permissions = [
            'admin' => ['all'],
            'manager' => ['read', 'write', 'update', 'delete'],
            'supervisor' => ['read', 'write', 'update'],
            'worker' => ['read'],
        ];

        return $permissions[$role] ?? ['read'];
    }
}
