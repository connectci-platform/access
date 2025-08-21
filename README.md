# ACCESS Module

A collection of Drupal modules for ACCESS Match and related cyberinfrastructure platforms.

## Overview

The ACCESS module is a custom Drupal module suite that provides functionality for the ACCESS (Advanced Cyberinfrastructure Coordination Ecosystem: Services & Support) ecosystem. This module contains several sub-modules that handle different aspects of the ACCESS platform, including affinity groups, CI links, mentorship, and various specialized features.

## Development Setup

### Prerequisites
- PHP 8.1+
- Drupal 9 or 10
- Composer

### Installation
1. Clone this repository into your Drupal installation's `web/modules/custom/access` directory
2. Install dependencies:
   ```bash
   composer install
   ```

3. Install pre-commit hooks for code quality:
   ```bash
   pre-commit install
   ```
   This ensures PHPStan analysis runs automatically before each commit.

### Code Quality
This module uses PHPStan for static analysis to catch issues before they reach production.

#### Linting Commands
```bash
# Run PHPStan analysis
composer lint

# Generate new baseline (when adding new code with acceptable issues)
composer lint:baseline
```

#### Pre-commit Hooks
- Automatically runs PHPStan on PHP files before commits
- Configuration: `.pre-commit-config.yaml`
- PHPStan config: `phpstan.neon`
- Baseline: `phpstan-baseline.neon`

## Sub-modules

### Core Modules

#### access_affinitygroup
**Purpose**: Custom functionality for Affinity Groups
- Manages affinity group memberships and interactions
- Provides blocks for affinity group content
- Handles email notifications and group communications
- Integrates with ConstantContact API

#### access_badges
**Purpose**: Badge system management
- Handles user achievement badges
- Provides badge display and management tools

#### access_cilink
**Purpose**: CI Link customizations  
- Manages cyberinfrastructure resource links
- Handles resource flagging and voting
- Provides resource view enhancements

#### access_events
**Purpose**: Event management functionality
- Event waitlist management
- Event instance sidebar blocks
- Integration with XSEDE API for event data

#### access_llm
**Purpose**: AI/LLM integration
- AI reference generation capabilities
- Integrates with OpenAI API

#### access_match_engagement
**Purpose**: Match engagement tracking
- Tracks user engagement with matches
- Provides engagement analytics and reporting

#### access_misc
**Purpose**: Miscellaneous ACCESS utilities
- User login customizations
- General site tools and utilities
- Security ticket creation
- People directory functionality
- Custom tags and search enhancements

#### access_news
**Purpose**: News and announcements
- News and events display blocks
- Request news submission functionality

#### access_outages
**Purpose**: System outage notifications
- Displays current system outages
- Affinity group-specific outage information

#### access_shortcodes
**Purpose**: Content shortcode system
- Accordion shortcodes
- CTA (Call-to-Action) blocks
- Icon blocks
- Square content blocks

### Platform-Specific Modules

#### ccmnet
**Purpose**: CCMnet (Campus Champions Mentorship Network) functionality
- Mentorship program management
- Mentor-mentee matching and tracking
- Mentorship engagement metrics
- User registration redirects

#### cssn
**Purpose**: CSSN (Community for Science Software and Network) features
- Community persona management
- User directory with skills/interests
- Project and match lookup functionality
- Join form and community features

#### ondemand
**Purpose**: OnDemand platform integration
- Basic OnDemand platform support

#### ticketing
**Purpose**: Support ticket system
- Account support ticket creation
- Organization list management
- Email notifications for tickets
- Webform integration

#### user_profiles
**Purpose**: Enhanced user profile functionality
- Extended user profile management
- Organization toggle features
- User profile commands and utilities

## Dependencies

### PHP Packages
- `openai-php/client`: OpenAI API integration
- `gioni06/gpt3-tokenizer`: GPT tokenization support

### Development Dependencies
- `phpstan/phpstan`: Static analysis
- `mglaman/phpstan-drupal`: Drupal-specific PHPStan rules

## Support

- **Issues**: [GitHub Issues](https://github.com/necyberteam/access/issues)
- **Source**: [GitHub Repository](https://github.com/necyberteam/access)

## Contributing

1. Fork the repository
2. Create a feature branch
3. Install pre-commit hooks: `pre-commit install`
4. Make your changes
5. Ensure all code quality checks pass
6. Submit a pull request

## License

GPL-2.0+

## Authors

- Miles France (@protitude)
- Andrew Pasquale
- FloQuinn (@FloQuinn)
- Hannah L Cameron (@laheara143)
- Jasper Lieber
- Lissie F (@lissief)
- Shawn Rice