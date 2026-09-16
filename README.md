# OpsPilot

**OpsPilot** is a business operations platform designed to bring everyday business workflows into one centralized system.

It combines customer management, job tracking, invoicing, payments, communications, website chat, and automation into a single PHP and MySQL application.

## Overview

Small businesses often rely on disconnected tools for customers, jobs, invoices, payments, and communication.

OpsPilot is designed around a different approach: one operational workspace where business activities can be managed and automated from a single system.

## Core Features

* Multi-tenant organization architecture
* User registration and authentication
* Role-based authorization
* Customer management
* Job management
* Invoice management
* Payment workflow
* Paystack integration
* Website chat channel
* Telegram integration
* Email communication
* Conversation and inbox management
* Business automation workflows
* CSRF protection
* Secure password hashing
* Session security
* Encrypted application secrets
* MySQL database architecture
* Responsive SaaS dashboard

## Technology Stack

### Backend

* PHP
* MySQL
* PDO
* REST-style API endpoints

### Frontend

* HTML
* CSS
* JavaScript

### Integrations

* Groq API
* Paystack
* Telegram Bot API
* Email
* Website Chat

## Architecture

```text
OpsPilot
│
├── app/
│   ├── Helpers/
│   └── Views/
│
├── config/
│
├── database/
│   └── SQL migrations
│
├── public/
│   ├── Authentication
│   ├── Dashboard
│   ├── Customers
│   ├── Jobs
│   ├── Invoices
│   ├── Channels
│   └── API endpoints
│
├── storage/
│
└── workers/
```

## Security

OpsPilot uses several application-level security mechanisms, including:

* Password hashing
* CSRF protection
* Session regeneration
* Role-based access control
* Prepared SQL statements
* Environment-based configuration
* Encrypted application secrets
* Multi-tenant organization isolation

Sensitive configuration values are intentionally excluded from the repository.

## Local Installation

### 1. Clone the repository

```bash
git clone https://github.com/YOUR_USERNAME/OpsPilot.git
cd OpsPilot
```

### 2. Create the environment file

Copy:

```text
.env.example
```

to:

```text
.env
```

Then configure your local database and API credentials.

### 3. Create the database

Create a MySQL database and import the SQL files from:

```text
database/
```

Apply them in their documented order.

### 4. Configure the application

Update:

```text
.env
```

with your local database credentials and required API keys.

### 5. Run the application

Place the project inside your PHP web server directory and serve the:

```text
public/
```

directory as the application entry point.

## Project Status

OpsPilot is an actively developed project. Core business management functionality is implemented, with additional integrations and deployment improvements being developed progressively.

## Purpose

This project demonstrates practical experience building a full-stack PHP application involving:

* SaaS architecture
* Database design
* Authentication and authorization
* Business workflow automation
* API integrations
* Payment processing
* Customer communication
* AI-assisted functionality
* Production deployment

## License

This project is currently provided for portfolio and demonstration purposes.
