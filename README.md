# Laravel Construction Management API

A comprehensive backend API for construction project management built with Laravel. This system provides multi-company support, project management, task tracking, inspections, snag reporting, document management, photo galleries, and real-time notifications.

## Features

### Core Features
- **Multi-company support** with role-based access control
- **Project management** with progress tracking and team assignment
- **Task management** with photo uploads, comments, and status tracking
- **Inspection system** with categories and quality control
- **Snag/issue reporting** with status workflow and photo evidence
- **Document management** with versioning and categorization
- **Photo gallery** with albums and metadata
- **Real-time notifications** with multiple channels
- **Comprehensive analytics** and dashboard endpoints

### Authentication & Authorization
- Laravel Sanctum for API authentication
- Multi-step user registration (User → Company → Team)
- Role-based permissions and middleware
- Company-based data isolation

## Tech Stack

- **Backend**: Laravel 10.x
- **Authentication**: Laravel Sanctum
- **Database**: MySQL/PostgreSQL
- **File Storage**: Laravel Storage (local/public/S3)
- **Queue**: Laravel Queues for notifications
- **API**: RESTful JSON API

## Installation

### Prerequisites
- PHP 8.1 or higher
- Composer
- MySQL/PostgreSQL
- Node.js & NPM (for frontend development)

### Setup Instructions

1. **Clone the repository**
   ```bash
   git clone [repository-url]
   cd biltix-construction-api
   ```

2. **Install dependencies**
   ```bash
   composer install
   npm install
   ```

3. **Environment configuration**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database setup**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

5. **Storage setup**
   ```bash
   php artisan storage:link
   ```

6. **Start development server**
   ```bash
   php artisan serve
   ```

## API Documentation

### Base URL
```
http://localhost:8000/api/v1
```

### Authentication Headers
```
Authorization: Bearer {your-token-here}
Content-Type: application/json
Accept: application/json
```

### Endpoints Overview

#### Authentication
- `POST /auth/register/step1` - User registration step 1
- `POST /auth/register/step2` - Company registration step 2
- `POST /auth/register/step3` - Team member invitation step 3
- `POST /auth/login` - User login
- `POST /auth/logout` - User logout
- `GET /profile` - Get user profile
- `PUT /profile` - Update user profile

#### Company Management
- `GET /companies` - List user's companies
- `POST /companies` - Create new company
- `GET /companies/{id}` - Get company details
- `PUT /companies/{id}` - Update company
- `DELETE /companies/{id}` - Delete company
- `POST /companies/{id}/invite` - Invite user to company
- `GET /companies/{id}/members` - Get company members

#### Project Management
- `GET /projects` - List projects
- `POST /projects` - Create new project
- `GET /projects/{id}` - Get project details
- `PUT /projects/{id}` - Update project
- `DELETE /projects/{id}` - Delete project
- `GET /projects/{id}/analytics` - Get project analytics
- `GET /projects/{id}/timeline` - Get project timeline

#### Task Management
- `GET /tasks` - List tasks
- `POST /tasks` - Create new task
- `GET /tasks/{id}` - Get task details
- `PUT /tasks/{id}` - Update task
- `DELETE /tasks/{id}` - Delete task
- `PUT /tasks/{id}/status` - Update task status
- `PUT /tasks/{id}/progress` - Update task progress
- `POST /tasks/{id}/assign` - Assign task to user
- `POST /tasks/{id}/photos` - Upload task photos
- `GET /tasks/{id}/photos` - Get task photos
- `POST /tasks/{id}/comments` - Add task comment
- `GET /tasks/{id}/comments` - Get task comments

#### Inspection Management
- `GET /inspections` - List inspections
- `POST /inspections` - Create new inspection
- `GET /inspections/{id}` - Get inspection details
- `PUT /inspections/{id}` - Update inspection
- `DELETE /inspections/{id}` - Delete inspection
- `PUT /inspections/{id}/conduct` - Mark inspection as conducted
- `POST /inspections/{id}/complete` - Complete inspection

#### Snag Report Management
- `GET /snags` - List snag reports
- `POST /snags` - Create new snag report
- `GET /snags/{id}` - Get snag report details
- `PUT /snags/{id}` - Update snag report
- `DELETE /snags/{id}` - Delete snag report
- `POST /snags/{id}/assign` - Assign snag to user
- `PUT /snags/{id}/status` - Update snag status
- `POST /snags/{id}/photos` - Upload snag photos

#### Document Management
- `GET /documents` - List documents
- `POST /documents` - Upload new document
- `GET /documents/{id}` - Get document details
- `PUT /documents/{id}` - Update document
- `DELETE /documents/{id}` - Delete document
- `GET /documents/{id}/download` - Download document
- `GET /documents/{id}/versions` - Get document versions
- `POST /documents/{id}/version` - Upload new version

#### Photo Gallery
- `GET /albums` - List photo albums
- `POST /albums` - Create new album
- `GET /albums/{id}` - Get album details
- `PUT /albums/{id}` - Update album
- `DELETE /albums/{id}` - Delete album
- `POST /albums/{id}/photos` - Upload photos to album
- `POST /albums/{id}/cover` - Set album cover photo

#### Notifications
- `GET /notifications` - List notifications
- `POST /notifications` - Create notification
- `GET /notifications/{id}` - Get notification details
- `PUT /notifications/{id}/read` - Mark as read
- `PUT /notifications/read-all` - Mark all as read
- `GET /notifications/unread-count` - Get unread count
- `DELETE /notifications/{id}` - Delete notification

#### Dashboard & Analytics
- `GET /dashboard` - Main dashboard overview
- `GET /dashboard/analytics` - Global analytics
- `GET /dashboard/tasks-summary` - Tasks summary
- `GET /dashboard/snags-summary` - Snags summary

## Data Models

### User
- Basic user information
- Company membership
- Role assignments

### Company
- Company details
- Team management
- Project assignment

### Project
- Project details
- Progress tracking
- Team assignment
- Timeline management

### Task
- Task details
- Status tracking
- Photo uploads
- Comments
- User assignments

### Inspection
- Inspection details
- Categories
- Status tracking
- Photo evidence
- Inspector assignment

### Snag Report
- Issue reporting
- Severity levels
- Status workflow
- Photo evidence
- User assignments

### Document
- File management
- Versioning
- Categorization
- Project association

### Photo Album
- Album organization
- Photo metadata
- Cover photos
- Project association

### Notification
- Real-time alerts
- Multiple channels
- Read/unread status
- User targeting

## Error Handling

### Response Format
```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field": ["error1", "error2"]
  }
}
```

### HTTP Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `500` - Server Error

## Testing

### Running Tests
```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --filter UserTest

# Run with coverage
php artisan test --coverage
```

### API Testing with Postman
Import the provided Postman collection: `docs/postman-collection.json`

### Manual Testing Checklist
- [ ] User registration (all 3 steps)
- [ ] User login/logout
- [ ] Company creation and management
- [ ] Project creation and updates
- [ ] Task creation with photos
- [ ] Inspection scheduling and completion
- [ ] Snag report creation and updates
- [ ] Document upload and versioning
- [ ] Photo album management
- [ ] Notification delivery
- [ ] Dashboard analytics

## File Storage

### Storage Structure
```
storage/app/public/
├── documents/
│   ├── {company-id}/
│   │   ├── {project-id}/
│   │   │   ├── {document-category}/
│   │   │   └── versions/
├── photos/
│   ├── {company-id}/
│   │   ├── {project-id}/
│   │   │   ├── tasks/
│   │   │   ├── inspections/
│   │   │   ├── snags/
│   │   │   └── albums/
└── thumbnails/
    └── {same-structure-as-photos}
```

### File Upload Limits
- Maximum file size: 10MB
- Allowed document types: PDF, DOC, DOCX, XLS, XLSX, TXT
- Allowed image types: JPG, JPEG, PNG, GIF, WebP
- Maximum photos per upload: 20

## Security

### Authentication
- JWT tokens with Laravel Sanctum
- Token expiration: 1 week
- Refresh token support

### Authorization
- Role-based access control
- Company-based data isolation
- Resource-level permissions

### Data Protection
- Password hashing with bcrypt
- File upload validation
- SQL injection prevention
- XSS protection
- Rate limiting

## Performance

### Caching
- Database query caching
- File metadata caching
- User session caching

### Optimization
- Database indexing
- Image optimization
- Lazy loading
- Pagination (default: 15 items per page)

## Deployment

### Environment Variables
```env
APP_NAME="Construction Management API"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=construction_api
DB_USERNAME=root
DB_PASSWORD=

FILESYSTEM_DISK=public
QUEUE_CONNECTION=database
```

### Production Checklist
- [ ] Set APP_ENV=production
- [ ] Set APP_DEBUG=false
- [ ] Configure proper database
- [ ] Set up SSL certificate
- [ ] Configure file storage (S3 recommended)
- [ ] Set up queue workers
- [ ] Configure backup system
- [ ] Set up monitoring

## Support

### Common Issues
1. **Storage link not working**: Run `php artisan storage:link`
2. **File upload errors**: Check PHP upload limits
3. **Database connection**: Verify .env configuration
4. **Queue not processing**: Start queue worker with `php artisan queue:work`

### Getting Help
- Check logs: `storage/logs/laravel.log`
- Run diagnostics: `php artisan route:list`
- Clear cache: `php artisan cache:clear`
- Optimize: `php artisan optimize`

## Contributing

1. Fork the repository
2. Create feature branch
3. Write tests for new features
4. Run tests: `php artisan test`
5. Submit pull request

## License

This project is licensed under the MIT License - see the LICENSE file for details.
