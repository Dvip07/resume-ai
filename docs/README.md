# AI Resume Generator Portal Documentation

Welcome to the comprehensive documentation for the AI Resume Generator Portal. This Laravel-based application combines web scraping, AI-powered resume analysis, and automated job matching to streamline the job application process.

## Quick Start

New to the project? Start here:

1. **[Installation Guide](setup/installation.md)** - Get the project running locally
2. **[Environment Configuration](setup/environment.md)** - Configure your environment variables
3. **[Database Setup](services/database.md)** - Set up your database and run migrations

## System Overview

The AI Resume Generator Portal is a comprehensive job application platform that includes:

- **Web Crawler**: Automated job scraping using Symfony Panther and ChromeDriver
- **AI Analysis**: Resume analysis and job matching using Ollama LLM
- **User Interface**: Laravel-based web application for resume management and job applications
- **Background Processing**: Queue-based job processing for scalable operations

## Documentation Structure

### 🚀 Setup & Installation
- [Installation Guide](setup/installation.md) - Basic installation steps
- [Environment Configuration](setup/environment.md) - Environment variables and configuration
- [Troubleshooting](setup/troubleshooting.md) - Common setup issues and solutions

### 🕷️ Web Crawler
- [Crawler Setup](crawler/setup.md) - ChromeDriver and crawler configuration
- [Crawler Usage](crawler/usage.md) - Running crawler commands and automation
- [Crawler Configuration](crawler/configuration.md) - Advanced settings and performance tuning

### 🔧 Services & Integration
- [Ollama Setup](services/ollama.md) - AI service configuration and model setup
- [Database Setup](services/database.md) - Database configuration and migrations
- [API Configuration](services/apis.md) - External API setup (Adzuna, SMTP)

### 🚀 Deployment
- [Development Environment](deployment/development.md) - Local development setup
- [Production Deployment](deployment/production.md) - Production environment configuration
- [Queue Workers](deployment/queue-workers.md) - Supervisor-managed `queue:work` in production
- [Monitoring & Logging](deployment/monitoring.md) - System monitoring and log management

### 📖 Usage & Features
- [User Guide](usage/user-guide.md) - End-user functionality and workflows
- [API Reference](usage/api-reference.md) - Internal API documentation

### 🏗️ Architecture & Development
- [System Architecture](architecture/overview.md) - Component diagrams and system design
- [Database Schema](architecture/database-schema.md) - Database structure and relationships
- [Contributing Guidelines](architecture/contributing.md) - Development standards and contribution workflow

## Key Features

### Resume Processing
- PDF resume upload and parsing
- AI-powered resume analysis using Ollama
- Automated resume tailoring for specific job applications

### Job Scraping & Matching
- Automated job scraping from multiple sources
- AI-powered job-resume matching
- Real-time job recommendation engine

### Application Management
- Automated job application submission
- Application tracking and history
- Email notifications and updates

## Prerequisites

Before getting started, ensure you have:

- PHP 8.1 or higher
- Composer
- Node.js and npm
- MySQL/PostgreSQL database
- ChromeDriver for web scraping
- Ollama for AI functionality

## Getting Help

- **Setup Issues**: Check the [Troubleshooting Guide](setup/troubleshooting.md)
- **Crawler Problems**: See [Crawler Configuration](crawler/configuration.md)
- **API Integration**: Review [API Configuration](services/apis.md)
- **Contributing**: Read [Contributing Guidelines](architecture/contributing.md)

## Project Structure

```
├── app/                    # Laravel application code
├── database/              # Migrations, seeders, factories
├── resources/             # Views, assets, frontend code
├── public/                # Web-accessible files
├── config/                # Configuration files
├── docs/                  # This documentation
└── tests/                 # Test suites
```

---

**Next Steps**: Start with the [Installation Guide](setup/installation.md) to get your development environment up and running.