# NCCI / EWCCV bypass key request

Ready to send whenever you want it. NCCI contact: **800-622-4123**, or the
contact form at https://www.ncci.com. The EWCCV site itself lists support
contacts under its help/contact links.

The ask is for a **reCAPTCHA bypass key** for `www.ewccv.com/cvs/` — the
mechanism their own application already implements
(`GET /cvs/endpoint/recaptcha/verifybypasskey/?key=…`), which is how they
support programmatic access. Support for it is already built into Hive: set
`EWCCV_BYPASS_KEY` in `.env` and the integration runs unattended.

Phone is likely faster than email; the script below works for either.

---

## Email

**To:** NCCI Customer Service (via ncci.com contact form, or the EWCCV support
address listed on the site)

**Subject:** Request for EWCCV coverage-verification bypass key — Illinois general contractor

Hello,

I'm writing to request a bypass key for the Workers' Compensation Coverage
Verification site (www.ewccv.com/cvs/), which the Illinois Workers'
Compensation Commission links to as the state's official employer coverage
lookup.

**Who we are.** GS Construction is a general contractor based in Illinois. We
hire subcontractors and are responsible for confirming that each one carries
active workers' compensation coverage before and during their work on our
projects — both to meet our own insurer's requirements and because an
uninsured sub's injury becomes our liability.

**What we do today.** We verify roughly a dozen subcontractors' policies and
re-check them on a regular cycle, since a certificate that was valid at
signing tells us nothing about a policy cancelled two months later. We use
EWCCV's search to confirm each policy, and its tracking feature to be notified
of cancellations and non-renewals. This is exactly the use the service is
intended for — we are simply doing it for every sub, every week, rather than
by hand one at a time.

**What we're asking for.** A bypass key for our account
(patryk.szady@live.com), so those periodic checks can run programmatically
against the same endpoints rather than through repeated manual searches. We
noticed the site supports this via its `verifybypasskey` endpoint and would
like to use the supported path rather than anything unsupported.

**Our commitments.** Modest, predictable volume — on the order of a dozen
lookups per week, run once weekly, not a continuous or bulk crawl. We are
happy to agree to a rate limit, terms of use, or any conditions you attach to
the key, and to identify our requests with a specific user agent or header if
that helps you attribute traffic.

If a bypass key isn't available for a contractor account, could you tell me
what the supported route is for this use case? If there is an appropriate
agreement, data service, or partner program for employers verifying their
subcontractors' coverage, we'd like to sign up for it.

Thank you,

Patryk Szady
GS Construction
patryk.szady@live.com

---

## If they say no

Ask these before hanging up / replying, in order:

1. **Is there a supported way at all** for an employer to verify subcontractor
   coverage programmatically — any agreement, tier, or partner program?
2. **Is Proof of Coverage (POC) available to us?** Their site restricts it to
   "regulators, industrial commissions, and accident boards," so likely no —
   but worth confirming, since we're doing state-mandated compliance work.
3. **Would an NCCI Affiliation Agreement apply to us?** Almost certainly not
   (it's for companies licensed to *write* workers comp), but it settles the
   question.
4. **Does IWCC offer anything directly?** The Illinois Workers' Compensation
   Commission Information Unit is 866-352-3033 / 312-814-6611, and the
   Department of Insurance Workers' Compensation Compliance Division handles
   coverage questions. If NCCI won't help an employer directly, the state
   agency whose mandate we're complying with is the next call.

Record whatever they say here — a documented "no" is what justifies paying for
a commercial COI-verification service instead.
