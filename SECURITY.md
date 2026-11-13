# Vulnerability disclosure

Please do not open public issues containing API keys, customer data, or exploitable credentials. Report suspected vulnerabilities privately using GitHub's **Report a vulnerability** feature (Security Advisories) if it is enabled for this repository; otherwise contact the repository owner through GitHub before sharing sensitive technical details.

Supported versions: this project is currently pre-release and has no supported production versions.

Never process a webhook without verifying the exact raw-body signature. For the optional inbox, protect APP_KEY and restrict access to encrypted receipt records. Operators must configure retention and test-mode/live-mode separation. Payment fulfillment is not automatic and requires server-side verification plus domain-specific idempotency.
