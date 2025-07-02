# WP SES Email Delivery

This plugin to track AWS Simple Email Service deliverability.

## Installation

- Install and activate the WP SES Email Delivery plugin.
- Place the `ses-webhook.php` file into the same WordPress installation directory.

## Setting in AWS Console for SES

1. Enable SNS Notifications in SES:
    - Go to AWS SES console
    - Navigate to "Email Sending" > "Configuration Sets"
    - Click "Create configuration set"
    - Give it a name (e.g., "EmailTracking")
    - Under "Event destinations", add destinations for:
        - Bounces
        - Complaints
        - Deliveries

    - Select "Amazon SNS" as the destination type

1. Configure SNS Topics:
    - For each event type, create or select an SNS topic
    - Configure subscribers for these topics (email, Lambda, SQS, etc.)

1. Apply Configuration Set:
    - When sending emails, attach this configuration set either:
        - In your API call (via ConfigurationSetName parameter)
        - Or set it as the default for your verified identities

## Setting in AWS Console for SNS

1. Set in SNS to the URL of your website:
    - Subscription URL: https://<yoursite>/ses-webhook.php