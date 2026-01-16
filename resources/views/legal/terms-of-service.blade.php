<x-layouts.guest>
<div class="max-w-4xl mx-auto px-4 py-12 bg-white min-h-screen">
    <h1 class="text-3xl font-bold mb-2">Terms of Service</h1>
    <p class="text-gray-500 mb-8">Last Updated: {{ now()->format('F j, Y') }}</p>

    <div class="prose prose-lg max-w-none">
        <p>
            These Terms of Service ("Terms") govern your access to and use of the <strong>{{ config('app.long_name') }}</strong>
            platform and services ("Service") provided by {{ config('app.long_name') }} ("we," "us," or "our").
            By accessing or using the Service, you agree to be bound by these Terms.
        </p>

        <h2>1. Acceptance of Terms</h2>
        <p>
            By creating an account or using the Service, you confirm that you are at least 18 years old,
            have the legal capacity to enter into these Terms, and agree to comply with all applicable laws
            and regulations.
        </p>

        <h2>2. Description of Service</h2>
        <p>
            {{ config('app.long_name') }} is a construction project management platform that provides:
        </p>
        <ul>
            <li>Project and task scheduling</li>
            <li>Client, vendor, and team management</li>
            <li>Estimate and invoice generation</li>
            <li>Financial transaction tracking and categorization</li>
            <li>Receipt and expense management</li>
            <li>SMS/text and email notifications</li>
            <li>Integration with third-party services (banking, email, maps)</li>
        </ul>

        <h2>3. Account Registration</h2>
        <p>
            You must provide accurate, complete, and current information when creating an account.
            You are responsible for:
        </p>
        <ul>
            <li>Maintaining the confidentiality of your login credentials</li>
            <li>All activities that occur under your account</li>
            <li>Notifying us immediately of any unauthorized access</li>
        </ul>
        <p>
            We reserve the right to suspend or terminate accounts that violate these Terms or contain
            false information.
        </p>

        <h2>4. Acceptable Use</h2>
        <p>You agree not to:</p>
        <ul>
            <li>Use the Service for any unlawful purpose</li>
            <li>Send spam, unsolicited messages, or harassing communications</li>
            <li>Attempt to gain unauthorized access to the Service or other users' accounts</li>
            <li>Interfere with or disrupt the Service or servers</li>
            <li>Upload malicious code, viruses, or harmful content</li>
            <li>Scrape, copy, or reproduce the Service without permission</li>
            <li>Use the Service to compete directly with us</li>
            <li>Violate any applicable laws or third-party rights</li>
        </ul>

        <h2>5. SMS/Text Message Terms</h2>

        <h3>5.1 Consent</h3>
        <p>
            By providing your phone number—whether directly, verbally, via text, email, or through a contractor
            who enters it into the {{ config('app.name') }} system on your behalf—and by agreeing to receive
            project-related communications, you expressly consent to receive SMS/MMS messages from
            {{ config('app.name') }} and its affiliates at the phone number provided. Consent is not a
            condition of purchase or service.
        </p>

        <h3>5.2 Message Types & Frequency</h3>
        <p>
            You may receive messages including project schedule updates, task reminders, schedule changes,
            vendor availability requests, and service notifications. Message frequency varies; you may receive
            multiple messages per day during active project periods.
        </p>

        <h3>5.3 Opting Out</h3>
        <p>
            You may opt out at any time by replying <strong>STOP</strong> to any message. After opting out,
            you will receive a confirmation message and will no longer receive SMS notifications.
            Reply <strong>START</strong> to re-subscribe.
        </p>

        <h3>5.4 Help</h3>
        <p>
            For assistance, reply <strong>HELP</strong> to any message or contact us at
            {{ config('services.twilio.from') ?: '[Support Number]' }}.
        </p>

        <h3>5.5 Rates & Carriers</h3>
        <p>
            Message and data rates may apply. Check with your mobile carrier for details.
            Carriers are not liable for delayed or undelivered messages.
        </p>

        <h3>5.6 Supported Carriers</h3>
        <p>
            The Service supports major U.S. carriers including AT&T, Verizon, T-Mobile, Sprint, and others.
            Carrier support is subject to change.
        </p>

        <h2>6. Third-Party Integrations</h2>

        <h3>6.1 Financial Services (Plaid)</h3>
        <p>
            By connecting your bank accounts through Plaid, you authorize {{ config('app.name') }} to access
            your financial account information via Plaid's services. You agree to Plaid's
            <a href="https://plaid.com/legal/#end-user-privacy-policy" target="_blank" rel="noopener">End User Privacy Policy</a>.
            We use this information solely to provide transaction syncing and expense management features.
        </p>

        <h3>6.2 Email Services (Nylas)</h3>
        <p>
            By connecting your email account through Nylas, you authorize us to access your email messages
            and calendar for receipt processing and communication features. Your use is subject to Nylas's
            <a href="https://www.nylas.com/legal/terms/" target="_blank" rel="noopener">Terms of Service</a>.
        </p>

        <h3>6.3 Maps & Location (Google)</h3>
        <p>
            The Service uses Google Maps APIs for address validation and mapping. Your use is subject to
            <a href="https://policies.google.com/terms" target="_blank" rel="noopener">Google's Terms of Service</a>.
        </p>

        <h3>6.4 Third-Party Disclaimer</h3>
        <p>
            We are not responsible for the availability, accuracy, or practices of third-party services.
            Your use of third-party integrations is at your own risk.
        </p>

        <h2>7. Payment Terms</h2>
        <p>
            If you subscribe to paid features, you agree to pay all applicable fees. Fees are non-refundable
            except as required by law or stated in a specific offer. We may change pricing with reasonable
            notice.
        </p>

        <h2>8. Intellectual Property</h2>
        <p>
            The Service, including its design, features, and content, is owned by {{ config('app.name') }}
            and protected by intellectual property laws. You retain ownership of data you input into the
            Service, but grant us a license to use it to provide and improve the Service.
        </p>

        <h2>9. Privacy</h2>
        <p>
            Your use of the Service is subject to our <a href="{{ route('legal.privacy') }}">Privacy Policy</a>,
            which describes how we collect, use, and share your information.
        </p>

        <h2>10. Disclaimer of Warranties</h2>
        <p>
            THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EXPRESS OR
            IMPLIED, INCLUDING MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.
            WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE.
        </p>

        <h2>11. Limitation of Liability</h2>
        <p>
            TO THE MAXIMUM EXTENT PERMITTED BY LAW, {{ strtoupper(config('app.name')) }} SHALL NOT BE LIABLE FOR
            ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS,
            REVENUE, DATA, OR BUSINESS OPPORTUNITIES ARISING FROM YOUR USE OF THE SERVICE.
        </p>
        <p>
            OUR TOTAL LIABILITY FOR ANY CLAIMS ARISING FROM THESE TERMS OR THE SERVICE SHALL NOT EXCEED
            THE AMOUNT YOU PAID US IN THE 12 MONTHS PRECEDING THE CLAIM, OR $100, WHICHEVER IS GREATER.
        </p>

        <h2>12. Indemnification</h2>
        <p>
            You agree to indemnify, defend, and hold harmless {{ config('app.name') }}, its officers, directors,
            employees, and agents from any claims, damages, losses, or expenses (including attorney's fees)
            arising from your use of the Service, violation of these Terms, or infringement of any rights.
        </p>

        <h2>13. Termination</h2>
        <p>
            You may terminate your account at any time by contacting us. We may suspend or terminate your
            access if you violate these Terms or for any reason with notice. Upon termination, your right
            to use the Service ceases, and we may delete your data after a reasonable retention period.
        </p>

        <h2>14. Dispute Resolution</h2>
        <p>
            Any disputes arising from these Terms or the Service shall be resolved through binding arbitration
            in accordance with the American Arbitration Association rules, except that either party may seek
            injunctive relief in court. Class action lawsuits and class-wide arbitration are waived.
        </p>

        <h2>15. Governing Law</h2>
        <p>
            These Terms are governed by and construed in accordance with the laws of the United States and the
            State of Colorado, without regard to conflict of law principles. Any legal action or proceeding
            arising under these Terms shall be brought exclusively in the federal or state courts located in
            Colorado, and the parties hereby consent to personal jurisdiction and venue therein.
        </p>

        <h2>16. Changes to Terms</h2>
        <p>
            We may modify these Terms at any time. We will notify you of material changes by posting the
            updated Terms and updating the "Last Updated" date. Continued use of the Service after changes
            constitutes acceptance.
        </p>

        <h2>17. Severability</h2>
        <p>
            If any provision of these Terms is found unenforceable, the remaining provisions will continue
            in effect.
        </p>

        <h2>18. Entire Agreement</h2>
        <p>
            These Terms, together with our Privacy Policy, constitute the entire agreement between you and
            {{ config('app.long_name') }} regarding the Service.
        </p>

        <h2>19. Contact Us</h2>
        <p>
            If you have questions about these Terms, please contact us:
        </p>
        <ul>
            <li><strong>Email:</strong> {{ config('mail.from.address') }}</li>
            <li><strong>Phone:</strong> {{ config('services.twilio.from') }}</li>
            <li><strong>Address:</strong> {{ config('app.physical_address') }}</li>
        </ul>

        <h3>19.1 Physical Mail & Communication Policy</h3>
        <p>
            We strongly prefer electronic communication (email or phone/SMS) for all correspondence as it allows us
            to respond more quickly and efficiently. If you choose to send physical mail to our address:
        </p>
        <ul>
            <li>Your communication <strong>must include</strong> a valid email address or phone number capable
                of receiving text messages so we can respond to you electronically.</li>
            <li>We do not accept signature-required or "sign to accept" deliveries. Such communications will be
                refused and returned to sender.</li>
            <li>If a return address is provided but no electronic contact method, we will return the communication
                to the sender with instructions to contact us electronically.</li>
            <li>Communications received without any return address or electronic contact information will be discarded,
                as we have no means to respond.</li>
            <li>Please allow up to <strong>45 days</strong> for a response to physical mail communications.</li>
            <li>By sending physical mail or any communication to us, you acknowledge that you have read and agree to
                these Terms of Service and our <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</li>
        </ul>
    </div>
</div>
</x-layouts.guest>
