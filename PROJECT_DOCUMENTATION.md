# 🚀 AI Resume Generator Portal - Complete Documentation

## 📋 Table of Contents
1. [Project Overview](#project-overview)
2. [Prerequisites](#prerequisites)
3. [Installation & Setup](#installation--setup)
4. [Running the Application](#running-the-application)
5. [Crawler System](#crawler-system)
6. [API Configuration](#api-configuration)
7. [Database Structure](#database-structure)
8. [Available Commands](#available-commands)
9. [Troubleshooting](#troubleshooting)
10. [Development Workflow](#development-workflow)

---

## 🎯 Project Overview

The **AI Resume Generator Portal** is a Laravel-based application that automates job searching and resume tailoring using AI. The system:

- **Analyzes uploaded resumes** using Ollama (local LLM)
- **Fetches job listings** from Adzuna API based on AI-suggested roles
- **Crawls job posting pages** using headless browser automation
- **Tracks applications** and enables AI-powered resume customization

### Tech Stack
- **Backend:** Laravel 12 (PHP 8.2+)
- **Frontend:** Blade Templates + Tailwind CSS
- **AI Engine:** Ollama (Local LLM like LLaMA3)
- **Web Crawler:** Symfony Panther (Headless Chrome)
- **Job API:** Adzuna API
- **Database:** MySQL
- **Queue System:** Laravel Queues
- **Scheduler:** Laravel Task Scheduler

---

## 🔧 Prerequisites

Before installation, ensure you have:

### Required Software
- **PHP 8.2+** with extensions: `pdo_mysql`, `mbstring`, `xml`, `curl`
- **MySQL 5.7+** or **MariaDB 10.3+**
- **Node.js 16+** and **npm**
- **Composer** (PHP dependency manager)
- **ChromeDriver** (for web crawling)
- **Ollama** (for AI functionality)

### System Requirements
- **Memory:** 4GB+ RAM (recommended 8GB for Ollama)
- **Storage:** 2GB+ free space
- **OS:** macOS, Linux, or Windows with WSL2

---

## 🛠️ Installation & Setup

### 1. Clone the Repository
```bash
git clone <your-repository-url>
cd ai-resume-portal
```

### 2. Install Dependencies
```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Build frontend assets
npm run dev
```

### 3. Environment Configuration
```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 4. Configure Environment Variables
Edit `.env` file with your settings:

```env
# Application
APP_NAME="AI Resume Portal"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=admin_resume
DB_USERNAME=root
DB_PASSWORD=your_password

# API Keys (Required)
ADZUNA_APP_ID=your_adzuna_app_id
ADZUNA_APP_KEY=your_adzuna_app_key
JSEARCH_RAPIDAPI_KEY=your_rapidapi_key
JOB_SOURCE_JSEARCH_ENABLED=false

# Queue Configuration
QUEUE_CONNECTION=database

# Session Configuration
SESSION_DRIVER=database
```

### 5. Database Setup
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE admin_resume;"

# Run migrations
php artisan migrate

# (Optional) Seed sample data
php artisan db:seed
```

### 6. ChromeDriver Installation

#### Option A: Download Manually
1. Download ChromeDriver from [chromedriver.chromium.org](https://chromedriver.chromium.org/)
2. Place the binary at: `{project_root}/drivers/chromedriver`
3. Make it executable: `chmod +x drivers/chromedriver`

#### Option B: Using Homebrew (macOS)
```bash
brew install chromedriver
```

#### Option C: Using Package Manager (Linux)
```bash
# Ubuntu/Debian
sudo apt-get install chromium-chromedriver

# CentOS/RHEL
sudo yum install chromium-chromedriver
```

### 7. Ollama Setup
```bash
# Install Ollama
curl -fsSL https://ollama.ai/install.sh | sh

# Pull a model (e.g., LLaMA3)
ollama pull llama3

# Start Ollama service
ollama serve
```

### 8. API Keys Setup

#### Adzuna API
1. Register at [developer.adzuna.com](https://developer.adzuna.com/)
2. Create an application
3. Copy `App ID` and `App Key` to `.env`

#### RapidAPI (Optional - for the JSearch aggregator)
1. Register at [rapidapi.com](https://rapidapi.com/)
2. Subscribe to [JSearch](https://rapidapi.com/letscrape-6bRBa3QguO5/api/jsearch)
3. Copy the API key to `backend/.env` as `JSEARCH_RAPIDAPI_KEY`

---

## 🚀 Running the Application

### 1. Start the Development Server
```bash
php artisan serve
```
Access the application at: `http://localhost:8000`

### 2. Start the Queue Worker (Required for Crawler)
```bash
php artisan queue:work
```

### 3. Start the Task Scheduler (Optional - for automated crawling)
```bash
# Add to crontab for production
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1

# For development, run manually
php artisan schedule:work
```

### 4. Start Frontend Build Process (Development)
```bash
npm run dev
```

---

## 🕷️ Crawler System

The crawler system is the core feature that automatically fetches job descriptions from job posting websites.

### How the Crawler Works

1. **Job Discovery:** Fetches job listings from Adzuna API
2. **URL Collection:** Stores job URLs in database
3. **Headless Browsing:** Uses Symfony Panther with Chrome to visit job pages
4. **Content Extraction:** Scrapes job descriptions using CSS selectors
5. **Database Update:** Stores full job descriptions for AI analysis

### Crawler Components

#### A. UpdateJobDescription Job (`app/Jobs/UpdateJobDescription.php`)
- **Purpose:** Queued job for scraping individual job postings
- **Execution:** Runs asynchronously in background
- **Features:** Error handling, logging, retry logic

#### B. UpdateJobDescriptions Command (`app/Console/Commands/UpdateJobDescriptions.php`)
- **Purpose:** Batch processing of multiple jobs
- **Execution:** `php artisan jobs:update-descriptions`
- **Features:** Concurrent processing (3 jobs simultaneously)

#### C. JobListingController (`app/Http/Controllers/JobListingController.php`)
- **Purpose:** Main controller with advanced scraping logic
- **Features:** Anti-detection measures, multiple CSS selectors, error handling

### Running the Crawler

#### Manual Execution
```bash
# Update all job descriptions
php artisan jobs:update-descriptions

# Process queue jobs
php artisan queue:work
```

#### Automatic Execution
The crawler runs automatically every 30 seconds when the scheduler is active:
```bash
php artisan schedule:work
```

#### Web Interface
Visit: `http://localhost:8000/update-job-descriptions`

### Crawler Features

#### Anti-Detection Measures
- **Random Delays:** 3-8 seconds between requests
- **Human-like Behavior:** Page scrolling simulation
- **Custom User-Agent:** Mimics real browser
- **JavaScript Waiting:** Waits for dynamic content to load

#### CSS Selectors (Multiple Fallbacks)
```css
.show-more-less-html__markup    /* LinkedIn */
.job-description                /* Generic */
.jobs-box__html-content         /* Indeed */
[data-automation="jobDescription"] /* Seek */
```

#### Error Handling
- Detects blocked/access-denied pages
- Takes screenshots for debugging
- Falls back to full page source
- Comprehensive logging

### Monitoring Crawler Activity

#### Check Logs
```bash
tail -f storage/logs/laravel.log
```

#### Database Monitoring
```sql
-- Check jobs with descriptions
SELECT COUNT(*) FROM job_listings WHERE description IS NOT NULL;

-- Recent crawler activity
SELECT * FROM job_listings ORDER BY updated_at DESC LIMIT 10;
```

#### Debug Screenshots
Error screenshots are saved to: `storage/logs/screenshot_*.png`

---

## 🔑 API Configuration

### Adzuna API
- **Endpoint:** `https://api.adzuna.com/v1/api/jobs/ca/search/1`
- **Rate Limit:** 1000 requests/month (free tier)
- **Configuration:** Set `ADZUNA_APP_ID` and `ADZUNA_APP_KEY` in `.env`

### Ollama API
- **Endpoint:** `http://localhost:11434` (default)
- **Models:** LLaMA3, Mistral, CodeLlama
- **Usage:** Resume analysis and role suggestions

### RapidAPI (Optional)
- **Service:** JSearch job aggregator (re-indexes LinkedIn/Indeed/Glassdoor
  postings). Replaces the retired LinkedIn Job API integration.
- **Configuration:** Set `JSEARCH_RAPIDAPI_KEY` (falls back to the older
  `RAPIDAPI_KEY`) and `JOB_SOURCE_JSEARCH_ENABLED=true` in `backend/.env`.
  Disabled by default.

---

## 🗄️ Database Structure

### Key Tables

#### job_listings
```sql
CREATE TABLE job_listings (
    id BIGINT PRIMARY KEY,
    api_id VARCHAR(255),
    title VARCHAR(255),
    company VARCHAR(255),
    location VARCHAR(255),
    description TEXT,           -- Scraped content
    api_source VARCHAR(50),     -- 'adzuna', 'linkedin'
    posted_at TIMESTAMP,
    is_active BOOLEAN DEFAULT 1,
    parsed_skills JSON,
    application_url VARCHAR(500), -- URL to scrape
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### user_profiles
```sql
CREATE TABLE user_profiles (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    skills JSON,
    experience JSON,
    education JSON,
    suggested_roles JSON,       -- AI-generated
    location JSON,
    resume_text TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### resume_uploads
```sql
CREATE TABLE resume_uploads (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    file_path VARCHAR(255),
    original_name VARCHAR(255),
    extracted_text TEXT,
    analysis_status ENUM('pending', 'completed', 'failed'),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## ⚡ Available Commands

### Crawler Commands
```bash
# Update job descriptions (main crawler)
php artisan jobs:update-descriptions

# Process background jobs
php artisan queue:work

# Start task scheduler
php artisan schedule:work
```

### Development Commands
```bash
# Clear all caches
php artisan optimize:clear

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# Seed database
php artisan db:seed
```

### Maintenance Commands
```bash
# Clear specific caches
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:clear

# Queue management
php artisan queue:restart
php artisan queue:failed
php artisan queue:retry all
```

---

## 🔧 Troubleshooting

### Common Issues

#### 1. ChromeDriver Not Found
**Error:** `Chrome driver not found`
**Solution:**
```bash
# Check ChromeDriver path
ls -la drivers/chromedriver

# Make executable
chmod +x drivers/chromedriver

# Or install via Homebrew
brew install chromedriver
```

#### 2. Database Connection Failed
**Error:** `SQLSTATE[HY000] [2002] Connection refused`
**Solution:**
```bash
# Check MySQL service
sudo systemctl status mysql

# Start MySQL
sudo systemctl start mysql

# Verify credentials in .env
```

#### 3. Ollama Connection Failed
**Error:** `Connection refused to localhost:11434`
**Solution:**
```bash
# Start Ollama service
ollama serve

# Check if model is available
ollama list

# Pull required model
ollama pull llama3
```

#### 4. Queue Jobs Not Processing
**Error:** Jobs stuck in queue
**Solution:**
```bash
# Start queue worker
php artisan queue:work

# Restart queue workers
php artisan queue:restart

# Check failed jobs
php artisan queue:failed
```

#### 5. Crawler Getting Blocked
**Error:** Access denied or empty descriptions
**Solution:**
- Check `storage/logs/laravel.log` for details
- Review debug screenshots in `storage/logs/`
- Adjust delays in crawler configuration
- Update CSS selectors for target websites

### Debug Mode

Enable detailed logging:
```env
APP_DEBUG=true
LOG_LEVEL=debug
```

Check logs:
```bash
tail -f storage/logs/laravel.log
```

---

## 🔄 Development Workflow

### 1. Feature Development
```bash
# Create feature branch
git checkout -b feature/new-crawler-selector

# Make changes
# Test locally

# Commit and push
git add .
git commit -m "Add new CSS selector for job site X"
git push origin feature/new-crawler-selector
```

### 2. Testing Crawler Changes
```bash
# Test single job scraping
php artisan tinker
>>> $job = App\Models\JobListing::first();
>>> dispatch(new App\Jobs\UpdateJobDescription($job->id, $job->application_url));

# Monitor logs
tail -f storage/logs/laravel.log
```

### 3. Database Migrations
```bash
# Create migration
php artisan make:migration add_new_field_to_job_listings

# Edit migration file
# Run migration
php artisan migrate
```

### 4. Adding New Job Sources
1. Update `JobListingController` with new API integration
2. Add CSS selectors for the new job site
3. Update database schema if needed
4. Test crawler with new source

### 5. Performance Optimization
```bash
# Optimize application
php artisan optimize

# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache
```

---

## 📊 Monitoring & Analytics

### Application Metrics
- **Job Listings:** Total jobs in database
- **Successful Scrapes:** Jobs with descriptions
- **Crawler Success Rate:** Percentage of successful scrapes
- **API Usage:** Adzuna API calls remaining

### Performance Monitoring
```bash
# Check queue status
php artisan queue:monitor

# Database performance
php artisan telescope:install  # Laravel Telescope for debugging
```

### Log Analysis
```bash
# Crawler success rate
grep "Updated description for job ID" storage/logs/laravel.log | wc -l

# Error analysis
grep "ERROR" storage/logs/laravel.log | tail -20
```

---

## 🚀 Production Deployment

### Server Requirements
- **PHP 8.2+** with OPcache enabled
- **MySQL 8.0+** with proper indexing
- **Redis** for caching and queues
- **Supervisor** for queue workers
- **Cron** for task scheduling

### Deployment Steps
```bash
# Optimize for production
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set up supervisor for queues
# Configure cron for scheduler
# Set up SSL certificate
# Configure web server (Nginx/Apache)
```

---

## 📞 Support & Contributing

### Getting Help
- Check this documentation first
- Review logs in `storage/logs/laravel.log`
- Check GitHub issues for similar problems
- Create detailed bug reports with logs and steps to reproduce

### Contributing
1. Fork the repository
2. Create feature branch
3. Make changes with tests
4. Submit pull request with detailed description

---

**Last Updated:** January 2026  
**Version:** 1.0.0  
**Laravel Version:** 12.x