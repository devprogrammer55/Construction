# 🔄 Code Pattern Guide - Step by Step Flow

This guide shows exactly how I write code from API request to response. Copy this pattern for any new project.

## 📋 Step 1: API Route Definition

```php
// routes/api.php
Route::prefix('v1')->middleware(['decrypt', 'verifyApiKey', 'language'])->group(function () {
    Route::post('signup', [AuthController::class, 'signup']);
});
```

## 📋 Step 2: Middleware Chain (Exact Order)

```php
// 1. RequestDecryption.php - Decrypt incoming request
public function handle(Request $request, Closure $next) {
    $decryptedData = EncryptDecrypt::bodyDecrypt($request->getContent());
    $request->merge(json_decode($decryptedData, true));
    return $next($request);
}

// 2. VerifyApiKey.php - Validate API key
public function handle(Request $request, Closure $next) {
    $apiKey = EncryptDecrypt::requestDecrypt($request->header('api-key'), 'api-key');
    if ($apiKey != config('constant.API_KEY')) {
        return response()->json(['code' => 401, 'message' => 'Invalid API key']);
    }
    return $next($request);
}

// 3. CheckUserToken.php - Validate user token (if required)
public function handle(Request $request, Closure $next) {
    $token = EncryptDecrypt::requestDecrypt($request->header('token'), 'token');
    $userDevice = UserDevice::where('token', $token)->first();
    if (!$userDevice) {
        return response()->json(['code' => 401, 'message' => 'Invalid token']);
    }
    $request['user_id'] = $userDevice->user_id;
    return $next($request);
}
```

## 📋 Step 3: Controller Method Structure

```php
// app/Http/Controllers/Api/AuthController.php
public function signup(Request $request) {
    try {
        // Step 3.1: Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'phone' => 'required',
            'user_type' => 'required',
            'first_name' => 'required',
            'password' => 'required|min:6',
            'device_type' => 'required|in:A,I',
        ], [
            'email.required' => trans('api.auth.email_required'),
            'email.email' => trans('api.auth.email_invalid'),
            // Add all custom messages
        ]);

        if ($validator->fails()) {
            return $this->validateResponse($validator->errors());
        }

        // Step 3.2: Business Logic
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
        $user->password = Hash::make($request->password);
        $user->step_no = 1;
        $user->save();

        // Step 3.4: Generate Token
        $accessToken = Str::random(64);
        UserDevice::create([
            'user_id' => $user->id,
            'token' => $accessToken,
            'device_type' => $request->device_type,
            'ip_address' => $request->ip(),
        ]);

        // Step 3.5: Prepare Response Data
        $responseData = [
            'user_id' => $user->id,
            'token' => $accessToken,
            'step_no' => $user->step_no,
            'email' => $user->email,
            'first_name' => $user->first_name,
        ];

        // Step 3.6: Return Response
        return $this->toJsonEnc($responseData, 'Registration successful', '200');

    } catch (Exception $e) {
        return $this->toJsonEnc([], $e->getMessage(), '500');
    }
}
```

## 📋 Step 4: Base Controller Response Methods

```php
// app/Http/Controllers/Controller.php
protected function toJsonEnc($data, $message, $code) {
    $response = [
        'code' => $code,
        'message' => $message,
        'data' => $data
    ];
    
    if (config('constant.ENCRYPTION_ENABLED') == 1) {
        return response()->json(EncryptDecrypt::bodyEncrypt(json_encode($response)));
    }
    
    return response()->json($response);
}

protected function validateResponse($errors) {
    $response = [
        'code' => '422',
        'message' => 'Validation failed',
        'errors' => $errors
    ];
    
    return response()->json($response, 422);
}
```

## 📋 Step 5: Encryption Helper Pattern

```php
// app/Helpers/EncryptDecrypt.php
class EncryptDecrypt {
    public static function bodyEncrypt($string) {
        $encryptionMethod = 'AES-256-CBC';
        $secret = hash('sha256', config('constant.SECRET'));
        $iv = config('constant.IV');
        return openssl_encrypt($string, $encryptionMethod, $secret, 0, $iv);
    }

    public static function bodyDecrypt($string) {
        $encryptionMethod = 'AES-256-CBC';
        $secret = hash('sha256', config('constant.SECRET'));
        $iv = config('constant.IV');
        return openssl_decrypt($string, $encryptionMethod, $secret, 0, $iv);
    }

    public static function requestDecrypt($encryptedContent, $type = '') {
        if (!empty($type) && ($type == 'api-key' || $type == 'token')) {
            return self::bodyDecrypt($encryptedContent);
        }
        return $encryptedContent;
    }
}
```

## 📋 Step 6: Model Structure Pattern

```php
// app/Models/User.php
class User extends Model {
    use HasFactory;

    protected $fillable = [
        'email', 'phone', 'country_code', 'user_type', 
        'first_name', 'last_name', 'profile_image', 'password',
        'step_no', 'business_name', 'business_logo', 'business_phone',
        'business_address', 'product_category_id', 'business_id_card',
        'otp', 'otp_expires_at', 'is_verified', 'is_active', 'is_deleted'
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    // Relationships
    public function devices() {
        return $this->hasMany(UserDevice::class);
    }

    public function products() {
        return $this->hasMany(Product::class);
    }
}
```

## 📋 Step 7: Complete Request Flow Example

```
1. Client sends encrypted request
   POST /api/v1/auth/signup
   Headers: api-key=[encrypted], Content-Type: application/json
   Body: [encrypted JSON]

2. RequestDecryption middleware decrypts body
   Raw JSON: {"email":"test@example.com","password":"123456"}

3. VerifyApiKey middleware validates API key
   Decrypts api-key header and matches with config

4. Controller processes request
   - Validates input
   - Checks existing user
   - Creates new user
   - Generates token
   - Returns response

5. Response encryption (if enabled)
   JSON response gets encrypted before sending to client

6. Client receives encrypted response
   Client decrypts response to get actual data
```

## 📋 Step 8: Quick Copy-Paste Templates

### 8.1 New API Endpoint Template

```php
// 1. Add route
Route::post('new_endpoint', [NewController::class, 'newMethod']);

// 2. Create controller method
public function newMethod(Request $request) {
    try {
        $validator = Validator::make($request->all(), [
            'field1' => 'required',
            'field2' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->validateResponse($validator->errors());
        }

        // Business logic here
        $data = Model::create($request->all());

        return $this->toJsonEnc($data, 'Success message', '200');
    } catch (Exception $e) {
        return $this->toJsonEnc([], $e->getMessage(), '500');
    }
}
```

### 8.2 Validation Rules Template

```php
$rules = [
    'email' => 'required|email|unique:users,email',
    'phone' => 'required|numeric|digits:10',
    'name' => 'required|string|max:255',
    'password' => 'required|min:6|confirmed',
    'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
    'status' => 'required|in:active,inactive,pending',
    'amount' => 'required|numeric|min:0',
];
```

### 8.3 Response Templates

```php
// Success
return $this->toJsonEnc($data, 'Operation successful', '200');

// Error
return $this->toJsonEnc([], 'Error occurred', '400');

// Not found
return $this->toJsonEnc([], 'Resource not found', '404');

// Validation error
return $this->validateResponse(['field' => ['Error message']]);
```

## 📋 Step 9: Folder Structure Pattern

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── [Name]Controller.php
│   └── Middleware/
│       ├── VerifyApiKey.php
│       ├── CheckUserToken.php
│       └── RequestDecryption.php
├── Models/
│   └── [ModelName].php
├── Helpers/
│   └── EncryptDecrypt.php
├── Services/
│   └── [ServiceName].php
└── Traits/
    └── ApiResponse.php
```

## 📋 Step 10: Environment Setup Checklist

```bash
# 1. Create .env
APP_NAME="ProjectName"
APP_KEY=base64:generate_new_key
DB_DATABASE=project_db
API_KEY=your_api_key_here
SECRET=your_secret_here
IV=your_iv_here
ENCRYPTION_ENABLED=1

# 2. Create config/constant.php
<?php
return [
    'API_KEY' => env('API_KEY'),
    'SECRET' => env('SECRET'),
    'IV' => env('IV'),
    'ENCRYPTION_ENABLED' => env('ENCRYPTION_ENABLED', 1),
    'GUESTTOKEN' => 'GUESTTOKEN',
];

# 3. Run commands
composer require guzzlehttp/guzzle
php artisan key:generate
php artisan migrate
```

---

## 🎯 Usage Instructions

1. **Copy this exact pattern** for any new API endpoint
2. **Follow the 10 steps** in order
3. **Use the templates** for quick development
4. **Maintain consistency** across all endpoints
