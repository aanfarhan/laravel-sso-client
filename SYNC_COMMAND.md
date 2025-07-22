# SSO Sync Command

The `sso:sync` command synchronizes user data between your OAuth client application and the OAuth server.

## Features

1. **Create users on server**: When a user exists on client but not on server, creates the user on the server
2. **Sync existing users**: Compares modification dates and syncs the most recent data
3. **Conflict resolution**: Handles data conflicts with interactive or automatic resolution
4. **Selective field sync**: Only syncs fields that exist on both client and server
5. **Batch processing**: Processes users in configurable batches for performance
6. **Role mapping**: Interactive or automatic role assignment during user sync
7. **Multi-client support**: Handles OAuth clients with multiple user access patterns

## Usage

### Basic Sync
```bash
php artisan sso:sync
```

### Dry Run (Preview Changes)
```bash
php artisan sso:sync --dry-run
```

### Multi-Client Support
```bash
# Grant access to this client when creating new users
php artisan sso:sync --grant-access

# Only sync users who have access to this client
php artisan sso:sync --verify-access

# Combine both options
php artisan sso:sync --grant-access --verify-access
```

### Role Mapping
```bash
# Assign a default role to all new users
php artisan sso:sync --default-role=user

# Enable interactive role mapping for new users
php artisan sso:sync --role-mapping

# Update roles for existing users during sync
php artisan sso:sync --role-mapping --update-roles

# Combine all role options
php artisan sso:sync --default-role=user --role-mapping --update-roles
```

### Scope Management
```bash
# Set specific scopes for user access (comma-separated)
php artisan sso:sync --scopes=read,write,admin

# Set scopes to none/empty for all users
php artisan sso:sync --no-scopes

# Update scopes for existing users during sync
php artisan sso:sync --scopes=read,write --update-scopes

# Interactive scope selection
php artisan sso:sync --interactive-scopes

# Combine role and scope management
php artisan sso:sync --role-mapping --scopes=read,write --update-roles --update-scopes
```

### Password Synchronization & Force Updates
```bash
# Force update existing server users with client data (overrides conflicts)
php artisan sso:sync --force-update-server

# Enable password synchronization for existing users
php artisan sso:sync --update-passwords

# Skip password synchronization entirely (passwords never sync)
php artisan sso:sync --skip-password-sync

# Combine force update with other options
php artisan sso:sync --force-update-server --role-mapping --scopes=read,write

# Test password compatibility for a specific user
php artisan sso:sync --test-password-sync=user@example.com
```

### Automatic Conflict Resolution
```bash
# Always use server data in conflicts
php artisan sso:sync --server-wins

# Always use client data in conflicts  
php artisan sso:sync --client-wins

# Skip prompts, use server data
php artisan sso:sync --force
```

### Custom Batch Size
```bash
php artisan sso:sync --batch-size=50
```

## Configuration

### Environment Variables
No additional environment variables are required! The sync command now uses OAuth 2.0 Client Credentials grant, which automatically uses your existing OAuth client configuration:

```env
# These are already configured for SSO authentication
LARAVELPASSPORT_CLIENT_ID=your-client-id
LARAVELPASSPORT_CLIENT_SECRET=your-client-secret
LARAVELPASSPORT_HOST=http://localhost:8000
```

### Config Options
In `config/sso-client.php`:
```php
// Fields that won't be overwritten during sync
'preserved_fields' => [
    'id_role', 'nik', 'address', 'nip_pbb', 
    'kd_propinsi', 'kd_dati2', 'kd_kecamatan', 'kd_kelurahan'
],
```

## Sync Rules

### 1. User Creation
- **Trigger**: User exists on client but not on server
- **Action**: Creates user on server with client data
- **Client Access**: With `--grant-access`, automatically grants access to the current OAuth client
- **Result**: User can now authenticate via SSO

### 2. User Synchronization  
- **Trigger**: User exists on both client and server
- **Action**: Compares `updated_at` timestamps and syncs newer data
- **Fields**: Only syncs common fields (name, email, username, password, etc.)
- **Access Control**: With `--verify-access`, only syncs users who have access to this OAuth client

### 3. Conflict Resolution
When data differs between client and server:

#### Interactive Mode (default)
- Shows conflicts for each user
- Prompts for resolution choice:
  - Use client data (update server)
  - Use server data (update client)  
  - Skip user

#### Automatic Modes
- `--server-wins`: Always use server data
- `--client-wins`: Always use client data
- `--force`: Same as `--server-wins`

### 4. Role Assignment
When creating users on the OAuth server, roles can be assigned:

#### Default Role Assignment
- **Trigger**: `--default-role` option specified
- **Action**: Assigns the specified role to all newly created users
- **Validation**: Role must exist on OAuth server

#### Interactive Role Mapping
- **Trigger**: `--role-mapping` option enabled
- **Action**: Sets up role mappings for ALL local user roles at the beginning
- **Local Role Detection**: Automatically detects roles from:
  - Spatie Laravel Permission roles (preferred)
  - `role`, `user_role`, `role_name`, `id_role` database fields
- **Mapping Process**:
  1. Shows available OAuth server roles
  2. Detects ALL unique local roles in your user base
  3. **Pre-maps all roles**: Prompts to map each local role before processing starts
  4. Applies mappings during user creation and sync (if `--update-roles` enabled)
  5. Option to skip role assignment for specific local roles

#### Role Updates for Existing Users
- **Trigger**: `--update-roles` option enabled (must be used with `--role-mapping`)
- **Action**: Updates roles for users that already exist on OAuth server
- **Behavior**: Applies role mappings to existing users during sync process

#### Combined Approach
- Use `--default-role`, `--role-mapping`, and `--update-roles` together
- Default role acts as fallback for users without specific local roles
- Interactive mapping applies to users with detected local roles
- Role updates ensure existing users get proper roles on OAuth server

### 5. Client Scope Management
When managing user access scopes to OAuth client applications:

#### Specific Scope Assignment
- **Trigger**: `--scopes` option specified with comma-separated values
- **Action**: Assigns the specified scopes to all users for this OAuth client
- **Common Scopes**: `read`, `write`, `admin`, `profile`, `email`, `roles`
- **Example**: `--scopes=read,write,profile` gives users read, write, and profile access

#### No Scopes Assignment
- **Trigger**: `--no-scopes` option enabled
- **Action**: Sets client access scopes to empty array `[]` for all users
- **Use Case**: When you want users to authenticate but have minimal access permissions
- **Security**: Users can login but cannot perform any scoped operations

#### Interactive Scope Selection
- **Trigger**: `--interactive-scopes` option enabled
- **Action**: Prompts for scope selection from predefined options
- **Available Options**:
  - `none` - No scopes (empty access)
  - `read` - Read access to user data
  - `write` - Write access to modify data  
  - `admin` - Administrative privileges
  - `profile` - Access to user profile information
  - `email` - Access to user email
  - `roles` - Access to user roles and permissions

#### Scope Updates for Existing Users
- **Trigger**: `--update-scopes` option enabled (used with other scope options)
- **Action**: Updates scopes for users that already exist on OAuth server
- **Behavior**: Applies scope settings to existing user client access during sync

#### Combined Scope and Role Management
- Use `--scopes`, `--no-scopes`, or `--interactive-scopes` with role options
- Scope management works independently of role assignment
- Both scopes and roles are applied during user creation and updates
- Example: `--role-mapping --scopes=read,write --update-roles --update-scopes`

### 6. Password Synchronization & Force Updates
Enhanced password handling and force update capabilities for existing users:

#### Force Update Server Users
- **Trigger**: `--force-update-server` option enabled
- **Action**: Forces all existing server users to be updated with client data, bypassing conflict resolution
- **Use Case**: When you want client data to always win without prompting for conflicts
- **Behavior**: 
  - Skips interactive conflict resolution
  - Updates server with all client user data
  - Includes password updates if not disabled
  - Works with role and scope updates

#### Password Synchronization Options
- **Default Behavior**: Passwords are included for new user creation
- **For Existing Users**: Password conflicts are detected and can be resolved interactively
- **Enhanced Options**:
  
#### Password Update for Existing Users
- **Trigger**: `--update-passwords` option enabled
- **Action**: Updates passwords on OAuth server for existing users when differences are detected
- **Security**: Password hashes are compared, not plain text
- **Conflict Handling**: Automatically resolves password conflicts by using client password

#### Skip Password Synchronization
- **Trigger**: `--skip-password-sync` option enabled
- **Action**: Completely skips password synchronization for all users
- **Use Case**: When you want to sync all other data but keep passwords separate
- **Security**: Prevents password data from being sent to OAuth server

#### Combined Force Update Usage
```bash
# Force update all data including passwords
php artisan sso:sync --force-update-server

# Force update with specific roles and scopes
php artisan sso:sync --force-update-server --role-mapping --scopes=read,write

# Update passwords specifically without forcing other conflicts
php artisan sso:sync --update-passwords

# Sync everything except passwords
php artisan sso:sync --skip-password-sync --role-mapping --scopes=read
```

#### Password Sync Behavior Summary
| Option | New Users | Existing Users | Conflicts |
|--------|-----------|----------------|-----------|
| Default | ✅ Password sent | ❌ Password conflicts shown | Interactive resolution |
| `--update-passwords` | ✅ Password sent | ✅ Password updated | Auto-resolve with client password |
| `--force-update-server` | ✅ Password sent | ✅ All data updated | Bypass all conflicts |
| `--skip-password-sync` | ❌ Password skipped | ❌ Password ignored | No password conflicts |

#### Password Compatibility Testing
The sync command now includes comprehensive password testing to ensure compatibility:

```bash
# Test password sync for a specific user
php artisan sso:sync --test-password-sync=user@example.com
```

This will test:
- **Hash Compatibility**: Whether client password hash works with OAuth server
- **Algorithm Detection**: Identifies hashing algorithms used (bcrypt, argon2, etc.)
- **Direct Comparison**: Tests if hashes match exactly
- **Plain Text Testing**: Interactive testing with actual passwords
- **Recommendations**: Provides specific advice for your setup

#### Common Password Issues & Solutions

**🚨 Problem**: Double-hashing (most common issue)
- **Cause**: Client sends hashed password, server hashes it again
- **Solution**: Updated OAuth server now detects pre-hashed passwords
- **Test**: Use `--test-password-sync` to verify compatibility

**⚠️ Problem**: Different hashing algorithms
- **Cause**: Client uses bcrypt, server uses argon2 (or vice versa)
- **Solution**: Use `--skip-password-sync` and reset passwords manually
- **Test**: Password testing will show algorithm mismatches

**✅ Solution**: Same algorithm and configuration
- **Result**: Password sync works perfectly
- **Test**: All tests pass in password compatibility check

### 7. Field Preservation
Local-only fields defined in `preserved_fields` are never overwritten during sync.

## Command Output

### Basic Sync Output
```
🔄 Starting SSO user sync...

✅ Connected to OAuth server successfully
📊 Found 150 users to process
[████████████████████████████████████████] 150/150

📊 Sync Summary:
  Created: 25 users
  Synced: 120 users  
  Conflicts: 3 users
  Skipped: 2 users

⚠️ Found 3 conflicts that need resolution:

Conflict #1 - User: john@example.com
  Field: name
    Client: John Smith (updated: 2025-01-15 10:30:00)
    Server: John Doe (updated: 2025-01-14 08:20:00)
    
How would you like to resolve this conflict?
  [client] Use client data (update server)
  [server] Use server data (update client)  
  [skip] Skip this user
```

### Role Mapping Output
```
🔄 Starting SSO user sync...

✅ Connected to OAuth server successfully
✅ Default role set: user

📋 Available roles on OAuth server:
  • admin (Administrator) - Can manage users and clients
  • super_admin (Super Administrator) - Has access to all system features
  • user (Regular User) - Basic user with limited access
  • user_manager (User Manager) - Can manage users and their access

🗂️  Local user roles found:
  • Kaban
  • Kabid
  • Kecamatan
  • Kelurahan/Desa

You will be prompted to map each local role to an OAuth server role during sync.

📊 Found 150 users to process

🎭 Role mapping for local role: 'Kaban'
   User: John Smith (john@example.com)
Map to OAuth server role:
  [0] Skip (no role assigned)
  [1] admin (Administrator)
  [2] super_admin (Super Administrator)
  [3] user (Regular User)
  [4] user_manager (User Manager)
 > 4

  → Mapped 'Kaban' to 'user_manager'

[████████████████████████████████████████] 150/150

📊 Sync Summary:
  Client ID: 0198162b-d015-71b7-8c41-181ab4e04fea
  Created: 25 users
  Synced: 120 users  
  Conflicts: 0 users
  Skipped: 5 users
  👤 Default role: user
  🎭 Role mapping enabled

📋 Role mappings used:
  • Kaban → user_manager
  • Kabid → admin
  • Kecamatan → user
  • Kelurahan/Desa → user
```

## OAuth Server Requirements

The OAuth server must have these API endpoints:

- `GET /api/users/search` - Search/list users
- `POST /api/users` - Create user (supports `roles` array and `client_scopes` array parameters)
- `PUT /api/users/{id}` - Update user (supports `roles` array and `client_scopes` array parameters)
- `GET /api/users/{id}/sync` - Get user sync data
- `GET /api/users/roles` - Get available roles for mapping
- `POST /api/users/test-password-sync` - Test password compatibility (supports `email`, `password`, `is_hashed` parameters)

## Security

- Uses OAuth 2.0 Client Credentials grant for secure API access
- Automatically handles token refresh and caching
- All API calls are authenticated and authorized
- Passwords are properly hashed when syncing
- Sensitive data is logged as `[hidden]` in conflict reports

## Error Handling

- Connection failures are handled gracefully
- Individual user sync errors don't stop the entire process
- Failed users are counted in the summary
- Detailed error logs are written to Laravel log files

## Best Practices

1. **Run dry-run first**: Always test with `--dry-run` before actual sync
2. **Schedule regularly**: Set up cron job for periodic syncing
3. **Monitor conflicts**: Review conflict patterns to improve data consistency  
4. **Backup data**: Backup both client and server before major syncs
5. **Use batch size**: Adjust `--batch-size` based on server performance