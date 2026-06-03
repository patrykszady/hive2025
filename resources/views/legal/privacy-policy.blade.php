<x-layouts.guest>
<div class="max-w-4xl mx-auto px-4 py-12 bg-white min-h-screen">
    <h1 class="text-3xl font-bold mb-2">Privacy Policy</h1>
    <p class="text-gray-500 mb-8">Last Updated: January 16, 2026</p>

    <div class="prose prose-lg max-w-none">
        <p>
            This Privacy Policy describes how <strong>{{ config('app.long_name') }}</strong> ("we," "us," or "our") collects,
            uses, and shares information when you use our construction project management platform and related services
            (the "Service"). We are committed to protecting your privacy and handling your data in an open and
            transparent manner.
        </p>

        <h2>1. Information We Collect</h2>

        <h3>1.1 Information You Provide</h3>
        <ul>
            <li><strong>Account Information:</strong> Name, email address, phone number, company name, and password when you register.</li>
            <li><strong>Business Information:</strong> Client details, project information, vendor/subcontractor details, addresses, and scheduling data.</li>
            <li><strong>Financial Information:</strong> Bank account information when you connect via Plaid for transaction syncing, expense tracking, and receipt management.</li>
            <li><strong>Communications:</strong> Messages, emails, and other communications you send through or store in the Service.</li>
        </ul>

        <h3>1.2 Information Collected Automatically</h3>
        <ul>
            <li><strong>Device & Usage Data:</strong> IP address, browser type, device type, operating system, pages visited, features used, and actions taken within the Service.</li>
            <li><strong>Log Data:</strong> Server logs that record requests made to our servers, including timestamps, URLs, and referring pages.</li>
            <li><strong>Location Data:</strong> General location information derived from your IP address; precise location only if you enable location services for mapping features.</li>
        </ul>

        <h3>1.3 Cookies & Tracking Technologies</h3>
        <p>We use cookies and similar technologies to provide, secure, and improve the Service:</p>
        <ul>
            <li><strong>Essential Cookies:</strong> Required for the Service to function (authentication, session management, security).</li>
            <li><strong>Analytics Cookies:</strong> Help us understand how users interact with the Service so we can improve it.</li>
        </ul>

        <h4>Third-Party Analytics Services</h4>
        <p>We use the following third-party analytics services:</p>
        <ul>
            <li>
                <strong>Google Analytics:</strong> We use Google Analytics to collect information about how visitors use our Service.
                Google Analytics uses cookies to collect data such as pages visited, time spent on pages, and how you arrived at our site.
                This information is used to compile reports and help us improve the Service. Google may also use this data in accordance with their privacy policy.
                You can opt out of Google Analytics by installing the <a href="https://tools.google.com/dlpage/gaoptout" target="_blank" rel="noopener">Google Analytics Opt-out Browser Add-on</a>.
                Learn more at <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google's Privacy Policy</a> and
                <a href="https://policies.google.com/terms" target="_blank" rel="noopener">Google's Terms of Service</a>.
            </li>
            <li>
                <strong>Microsoft Clarity:</strong> We use Microsoft Clarity to understand how users interact with our Service through
                session recordings and heatmaps. Clarity captures how you use and interact with our website through behavioral metrics,
                heatmaps, and session replays. Website usage data is captured using first-party cookies and other tracking technologies.
                This data does not include your personal information. For more information, see
                <a href="https://privacy.microsoft.com/en-us/privacystatement" target="_blank" rel="noopener">Microsoft's Privacy Statement</a> and
                <a href="https://www.microsoft.com/en-us/servicesagreement" target="_blank" rel="noopener">Microsoft Services Agreement</a>.
            </li>
        </ul>

        <h4>Managing Cookies</h4>
        <p>
            Most web browsers allow you to control cookies through their settings. You can typically find these settings in your
            browser's "Options" or "Preferences" menu. Note that disabling certain cookies may affect the functionality of the Service.
        </p>

        <h3>1.4 Email Tracking</h3>
        <p>
            When enabled for business communications sent through the Service, we may track email opens and link clicks
            to help you understand engagement with your project communications. This tracking uses small transparent images
            (pixels) embedded in emails.
        </p>

        <h3>1.5 Information from Third-Party Services</h3>
        <ul>
            <li><strong>Plaid:</strong> When you connect your bank accounts, we receive transaction data, account balances, and account holder information via Plaid. See Section 4.</li>
            <li><strong>Nylas:</strong> When you connect your email account, we access email messages, calendar events, and contacts as needed to provide receipt processing and communication features. See Section 5.</li>
            <li><strong>Google Maps:</strong> We use Google Maps APIs for address validation, geocoding, and mapping features. Google's privacy policy applies to this data.</li>
        </ul>

        <h2>2. How We Use Your Information</h2>
        <ul>
            <li>Provide, maintain, and improve the Service</li>
            <li>Process transactions and manage your account</li>
            <li>Send project updates, schedule notifications, and service communications</li>
            <li>Send SMS/text message notifications (with your consent)</li>
            <li>Sync and categorize financial transactions</li>
            <li>Process and organize email receipts</li>
            <li>Respond to your requests and provide customer support</li>
            <li>Detect, prevent, and address technical issues and security threats</li>
            <li>Comply with legal obligations</li>
        </ul>

        <h2>3. SMS/Text Message Communications</h2>
        <p>
            We may send you SMS/text messages for project schedule updates, task notifications, and service alerts.
            By providing your phone number and opting in, you consent to receive these messages.
        </p>

        <h3>3.1 Types of Messages</h3>
        <ul>
            <li>Project schedule notifications (today's/tomorrow's tasks)</li>
            <li>Schedule change alerts</li>
            <li>Vendor availability requests</li>
            <li>Service notifications and alerts</li>
        </ul>

        <h3>3.2 Message Frequency</h3>
        <p>Message frequency varies based on your project activity. You may receive multiple messages per day during active project periods.</p>

        <h3>3.3 Opting Out</h3>
        <p>
            You can opt out of SMS messages at any time by replying <strong>STOP</strong> to any message.
            Reply <strong>HELP</strong> for assistance. Message and data rates may apply.
        </p>

        <h3>3.4 Carrier Disclaimer</h3>
        <p>
            Carriers are not liable for delayed or undelivered messages. We use third-party SMS providers
            (such as Telnyx) to deliver messages.
        </p>

        <h2>4. Financial Data (Plaid)</h2>
        <p>
            When you connect bank accounts through Plaid, you authorize Plaid to access your financial data on your behalf.
            We receive and store:
        </p>
        <ul>
            <li>Account and routing numbers (encrypted)</li>
            <li>Transaction history (descriptions, amounts, dates, categories)</li>
            <li>Account balances</li>
            <li>Account holder name</li>
        </ul>
        <p>
            We use this data solely for expense tracking, transaction categorization, and financial reporting within the Service.
            We do not sell your financial data. Plaid's use of your data is governed by
            <a href="https://plaid.com/legal/" target="_blank" rel="noopener">Plaid's Privacy Policy</a>.
        </p>
        <p>
            You can disconnect your bank accounts at any time through your account settings or by contacting us.
        </p>

        <h2>5. Email Integration (Nylas)</h2>
        <p>
            When you connect your email account through Nylas, you authorize us to access:
        </p>
        <ul>
            <li>Email messages (for receipt processing and forwarding)</li>
            <li>Email metadata (sender, recipient, subject, timestamps)</li>
            <li>Calendar events (if enabled)</li>
        </ul>
        <p>
            We use this access to automatically process expense receipts, track email communications, and provide
            integrated messaging features. We do not read or store personal emails unrelated to the Service.
            Nylas's use of your data is governed by <a href="https://www.nylas.com/legal/privacy-policy/" target="_blank" rel="noopener">Nylas's Privacy Policy</a>.
        </p>

        <h2>6. Voice Calls, Recordings, Transcripts & AI Summaries</h2>
        <p>
            Phone calls placed to or received from {{ config('app.name') }} phone numbers are routed through Telnyx and may be
            recorded, transcribed, and summarized using artificial intelligence (Azure OpenAI). We collect and store:
        </p>
        <ul>
            <li>Caller and called phone numbers, call direction, duration, and timestamps</li>
            <li>Audio recordings of the call (typically MP3, dual-channel)</li>
            <li>Text transcripts of the call, including detected language</li>
            <li>AI-generated summaries, action items, sentiment, and topic tags derived from the transcript</li>
            <li>Call metadata associated with the project, client, or vendor on the platform</li>
        </ul>
        <p>
            <strong>Consent.</strong> An audible announcement and/or beep is played at the start of each recorded call.
            By remaining on the call after the announcement, all parties consent to the call being recorded, transcribed,
            and summarized. You may end the call at any time if you do not consent.
        </p>
        <p>
            <strong>Use.</strong> We use call recordings, transcripts, and summaries to provide service continuity, resolve
            disputes, train staff, improve product quality, and surface action items in your account. We do not sell call
            content. Calls in languages other than English may be transcribed and summarized into English by Azure OpenAI.
        </p>
        <p>
            <strong>Retention.</strong> Audio recordings, transcripts, and AI summaries are automatically deleted after
            <strong>180 days</strong>, unless a longer retention is required by law, to resolve a dispute, or at your
            written request.
        </p>
        <p>
            <strong>Subprocessors.</strong> Telnyx provides voice transport and speech-to-text. Azure OpenAI generates
            summaries; per Microsoft's terms, Azure OpenAI does not use customer call content to train its foundation
            models. Their handling of data is governed by
            <a href="https://telnyx.com/data-privacy" target="_blank" rel="noopener">Telnyx's Data &amp; Privacy</a> and
            <a href="https://www.microsoft.com/en-us/trust-center/privacy" target="_blank" rel="noopener">Microsoft's Privacy Statement</a>.
        </p>

        <h2>7. Data Sharing</h2>
        <p>We do not sell your personal information. We may share information with:</p>
        <ul>
            <li><strong>Service Providers:</strong> Third parties who help us operate the Service (hosting, SMS delivery, payment processing, email integration).</li>
            <li><strong>Business Partners:</strong> Vendors, subcontractors, and clients you work with through the platform (only project-related information necessary for collaboration).</li>
            <li><strong>Legal Requirements:</strong> When required by law, legal process, or to protect our rights and safety.</li>
            <li><strong>Business Transfers:</strong> In connection with a merger, acquisition, or sale of assets.</li>
        </ul>

        <h2>8. Data Security</h2>
        <p>
            We implement industry-standard security measures to protect your data, including:
        </p>
        <ul>
            <li>Encryption in transit (TLS/SSL) and at rest</li>
            <li>Secure password hashing</li>
            <li>Access controls and authentication</li>
            <li>Regular security assessments</li>
        </ul>
        <p>
            However, no method of transmission or storage is 100% secure. You are responsible for maintaining
            the confidentiality of your account credentials.
        </p>

        <h2>9. Data Retention</h2>
        <p>
            We retain your data for as long as your account is active or as needed to provide the Service.
            We may retain certain information as required by law or for legitimate business purposes
            (e.g., tax records, dispute resolution).
        </p>
        <p>
            You can request deletion of your account and associated data by contacting us. Some data may
            be retained in backups for a limited period.
        </p>

        <h2>10. Your Rights</h2>
        <p>Depending on your location, you may have the right to:</p>
        <ul>
            <li>Access the personal information we hold about you</li>
            <li>Correct inaccurate information</li>
            <li>Delete your personal information</li>
            <li>Object to or restrict certain processing</li>
            <li>Data portability</li>
            <li>Withdraw consent</li>
        </ul>
        <p>To exercise these rights, contact us using the information below.</p>

        <h2>11. Children's Privacy</h2>
        <p>
            The Service is not intended for children under 18. We do not knowingly collect personal information
            from children. If you believe we have collected information from a child, please contact us.
        </p>

        <h2>12. Changes to This Policy</h2>
        <p>
            We may update this Privacy Policy from time to time. We will notify you of material changes by
            posting the new policy on this page and updating the "Last Updated" date.
        </p>

        <h2>13. Contact Us</h2>
        <p>
            If you have questions about this Privacy Policy, our data practices, or wish to exercise your rights,
            please contact us:
        </p>
        <ul>
            <li><strong>Email:</strong> {{ config('mail.from.address') }}</li>
            <li><strong>Phone:</strong> {{ config('services.telnyx.from') }}</li>
            <li><strong>Address:</strong> {{ config('app.physical_address') }}</li>
        </ul>
    </div>
</div>
</x-layouts.guest>
