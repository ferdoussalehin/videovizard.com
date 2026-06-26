# LinkedIn API Integration

This PHP application allows you to authenticate with LinkedIn and post content to your LinkedIn profile.

## Security Improvements

This application has been designed with security in mind:

1. **No sensitive data in files** - API credentials (client ID and secret) are only stored in environment variables, never in files
2. **Session data separation** - Only non-sensitive session data is stored in JSON files
3. **Environment variable management** - Credentials can be loaded from a `.env` file (not committed to version control)

## Environment Variables Setup

For security reasons, LinkedIn API credentials are stored ONLY in environment variables, not in any files.

### Option 1: Using a .env file (recommended for development)

1. Copy the `env.example` file to `.env` in the same directory:
   ```
   cp env.example .env
   ```

2. Edit the `.env` file and add your LinkedIn API credentials:
   ```
   LINKEDIN_CLIENT_ID=your_linkedin_client_id_here
   LINKEDIN_CLIENT_SECRET=your_linkedin_client_secret_here
   ```

3. Make sure your `.env` file is added to `.gitignore` to prevent it from being committed to version control.

### Option 2: Setting environment variables directly (recommended for production)

You can also set the environment variables directly in your system:

```bash
export LINKEDIN_CLIENT_ID=your_linkedin_client_id_here
export LINKEDIN_CLIENT_SECRET=your_linkedin_client_secret_here
```

Or in your web server configuration (e.g., Apache):

```
SetEnv LINKEDIN_CLIENT_ID your_linkedin_client_id_here
SetEnv LINKEDIN_CLIENT_SECRET your_linkedin_client_secret_here
```

## Usage

1. Start by authenticating with LinkedIn using the index.php file
2. After authentication, you can post content using post.php 