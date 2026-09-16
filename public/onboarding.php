<?php

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();

$organization =
    currentOrganization();

if (!$organization) {

    logoutUser();

    redirect('login.php');
}

$pdo = db();

$stmt =
    $pdo->prepare(
        'SELECT *
         FROM organization_settings
         WHERE organization_id = ?
         LIMIT 1'
    );

$stmt->execute([
    currentOrganizationId()
]);

$settings =
    $stmt->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $industry =
        trim(
            $_POST['industry'] ?? ''
        );

    $phone =
        trim(
            $_POST['phone'] ?? ''
        );

    $website =
        trim(
            $_POST['website'] ?? ''
        );

    $address =
        trim(
            $_POST['address'] ?? ''
        );

    $city =
        trim(
            $_POST['city'] ?? ''
        );

    $state =
        trim(
            $_POST['state'] ?? ''
        );

    $country =
        trim(
            $_POST['country'] ?? 'Nigeria'
        );

    $currency =
        trim(
            $_POST['currency'] ?? 'NGN'
        );

    $timezone =
        trim(
            $_POST['timezone']
            ?? 'Africa/Lagos'
        );

    $channels = [
        'phone' =>
            isset($_POST['channels']['phone']),

        'email' =>
            isset($_POST['channels']['email']),

        'whatsapp' =>
            isset($_POST['channels']['whatsapp']),

        'website' =>
            isset($_POST['channels']['website']),

        'instagram' =>
            isset($_POST['channels']['instagram']),
    ];


    if ($industry === '') {

        $errors[] =
            'Please select your business type.';
    }


    if (!$errors) {

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Update organization
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'UPDATE organizations
                     SET
                        industry = ?,
                        phone = ?,
                        website = ?,
                        address = ?,
                        city = ?,
                        state = ?,
                        country = ?,
                        currency = ?,
                        timezone = ?
                     WHERE id = ?'
                );

            $stmt->execute([
                $industry,
                $phone ?: null,
                $website ?: null,
                $address ?: null,
                $city ?: null,
                $state ?: null,
                $country,
                $currency,
                $timezone,
                currentOrganizationId()
            ]);


            /*
            |--------------------------------------------------------------------------
            | Save communication channels
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'UPDATE organization_settings
                     SET
                        communication_channels = ?,
                        onboarding_data = ?
                     WHERE organization_id = ?'
                );

            $onboardingData =
                json_encode([
                    'completed_at' =>
                        date('c'),

                    'industry' =>
                        $industry,

                    'channels' =>
                        $channels
                ]);


            $stmt->execute([
                json_encode($channels),
                $onboardingData,
                currentOrganizationId()
            ]);


            /*
            |--------------------------------------------------------------------------
            | Mark onboarding complete
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'UPDATE organizations
                     SET onboarding_completed = TRUE
                     WHERE id = ?'
                );

            $stmt->execute([
                currentOrganizationId()
            ]);


            $pdo->commit();

            redirect(
                'dashboard.php?welcome=1'
            );

        } catch (
            Throwable $e
        ) {

            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            $errors[] =
                'We could not save your setup. Please try again.';
        }
    }
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Set up your business — OpsPilot</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >

    <link
        rel="stylesheet"
        href="css/tokens.css"
    >

    <link
        rel="stylesheet"
        href="css/app.css"
    >

</head>

<body class="onboarding-page">

<header class="onboarding-header">

    <a
        href="dashboard.php"
        class="auth-logo"
    >

        <span class="brand-mark">
            OP
        </span>

        <span>
            OpsPilot
        </span>

    </a>

    <div class="onboarding-progress">

        <span class="progress-active"></span>
        <span></span>
        <span></span>
        <span></span>

    </div>

    <span class="step-counter">
        01 / 04
    </span>

</header>


<main class="onboarding-main">

    <div class="onboarding-intro">

        <span class="eyebrow">
            LET'S CONFIGURE YOUR WORKSPACE
        </span>

        <h1>
            Teach OpsPilot
            how your business works.
        </h1>

        <p>
            These details help us configure
            your dashboard, automations,
            booking rules and AI receptionist.
        </p>

    </div>


    <?php if ($errors): ?>

        <div class="alert alert-error">

            <i class="fa-solid fa-circle-exclamation"></i>

            <?= e($errors[0]) ?>

        </div>

    <?php endif; ?>


    <form
        method="POST"
        class="onboarding-form"
    >

        <?= csrf_field() ?>


        <section class="setup-section">

            <div class="setup-section-heading">

                <span class="section-number">
                    01
                </span>

                <div>

                    <h2>
                        Business identity
                    </h2>

                    <p>
                        Tell us what kind of operation
                        we're helping run.
                    </p>

                </div>

            </div>


            <div class="field-grid">

                <div class="field">

                    <label for="industry">
                        Business type
                    </label>

                    <select
                        id="industry"
                        name="industry"
                        required
                    >

                        <option value="">
                            Select your industry
                        </option>

                        <option value="salon">
                            Salon / Beauty
                        </option>

                        <option value="restaurant">
                            Restaurant / Food
                        </option>

                        <option value="retail">
                            Retail
                        </option>

                        <option value="automotive">
                            Automotive
                        </option>

                        <option value="professional_services">
                            Professional Services
                        </option>

                        <option value="home_services">
                            Home Services
                        </option>

                        <option value="healthcare">
                            Healthcare
                        </option>

                        <option value="nonprofit">
                            Nonprofit / NGO
                        </option>

                        <option value="agriculture">
                            Agriculture
                        </option>

                        <option value="other">
                            Other
                        </option>

                    </select>

                </div>


                <div class="field">

                    <label for="phone">
                        Business phone
                    </label>

                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        placeholder="+234 800 000 0000"
                    >

                </div>


                <div class="field">

                    <label for="website">
                        Website
                    </label>

                    <input
                        id="website"
                        name="website"
                        type="url"
                        placeholder="https://yourbusiness.com"
                    >

                </div>


                <div class="field">

                    <label for="currency">
                        Currency
                    </label>

                    <select
                        id="currency"
                        name="currency"
                    >

                        <option value="NGN">
                            ₦ Nigerian Naira
                        </option>

                        <option value="USD">
                            $ US Dollar
                        </option>

                        <option value="GBP">
                            £ British Pound
                        </option>

                        <option value="EUR">
                            € Euro
                        </option>

                    </select>

                </div>

            </div>

        </section>


        <section class="setup-section">

            <div class="setup-section-heading">

                <span class="section-number">
                    02
                </span>

                <div>

                    <h2>
                        Where you operate
                    </h2>

                    <p>
                        This keeps schedules,
                        appointments and notifications accurate.
                    </p>

                </div>

            </div>


            <div class="field-grid">

                <div class="field">

                    <label for="country">
                        Country
                    </label>

                    <input
                        id="country"
                        name="country"
                        value="Nigeria"
                    >

                </div>


                <div class="field">

                    <label for="state">
                        State / Region
                    </label>

                    <input
                        id="state"
                        name="state"
                        placeholder="Lagos"
                    >

                </div>


                <div class="field">

                    <label for="city">
                        City
                    </label>

                    <input
                        id="city"
                        name="city"
                        placeholder="Ikeja"
                    >

                </div>


                <div class="field">

                    <label for="timezone">
                        Timezone
                    </label>

                    <select
                        id="timezone"
                        name="timezone"
                    >

                        <option value="Africa/Lagos">
                            Africa/Lagos
                        </option>

                        <option value="Africa/Accra">
                            Africa/Accra
                        </option>

                        <option value="Europe/London">
                            Europe/London
                        </option>

                        <option value="America/New_York">
                            America/New York
                        </option>

                    </select>

                </div>


                <div class="field field-full">

                    <label for="address">
                        Business address
                    </label>

                    <input
                        id="address"
                        name="address"
                        placeholder="123 Business Street"
                    >

                </div>

            </div>

        </section>


        <section class="setup-section">

            <div class="setup-section-heading">

                <span class="section-number">
                    03
                </span>

                <div>

                    <h2>
                        Customer channels
                    </h2>

                    <p>
                        Where do your customers usually
                        reach your business?
                    </p>

                </div>

            </div>


            <div class="channel-grid">

                <label class="channel-card">

                    <input
                        type="checkbox"
                        name="channels[phone]"
                        checked
                    >

                    <span class="channel-icon">
                        <i class="fa-solid fa-phone"></i>
                    </span>

                    <span class="channel-copy">

                        <strong>
                            Phone
                        </strong>

                        <small>
                            Calls & enquiries
                        </small>

                    </span>

                    <span class="check-mark">
                        <i class="fa-solid fa-check"></i>
                    </span>

                </label>


                <label class="channel-card">

                    <input
                        type="checkbox"
                        name="channels[email]"
                        checked
                    >

                    <span class="channel-icon">
                        <i class="fa-solid fa-envelope"></i>
                    </span>

                    <span class="channel-copy">

                        <strong>
                            Email
                        </strong>

                        <small>
                            Email enquiries
                        </small>

                    </span>

                    <span class="check-mark">
                        <i class="fa-solid fa-check"></i>
                    </span>

                </label>


                <label class="channel-card">

                    <input
                        type="checkbox"
                        name="channels[whatsapp]"
                    >

                    <span class="channel-icon">
                        <i class="fa-brands fa-whatsapp"></i>
                    </span>

                    <span class="channel-copy">

                        <strong>
                            WhatsApp
                        </strong>

                        <small>
                            Messages & bookings
                        </small>

                    </span>

                    <span class="check-mark">
                        <i class="fa-solid fa-check"></i>
                    </span>

                </label>


                <label class="channel-card">

                    <input
                        type="checkbox"
                        name="channels[website]"
                        checked
                    >

                    <span class="channel-icon">
                        <i class="fa-solid fa-globe"></i>
                    </span>

                    <span class="channel-copy">

                        <strong>
                            Website
                        </strong>

                        <small>
                            Website visitors
                        </small>

                    </span>

                    <span class="check-mark">
                        <i class="fa-solid fa-check"></i>
                    </span>

                </label>


                <label class="channel-card">

                    <input
                        type="checkbox"
                        name="channels[instagram]"
                    >

                    <span class="channel-icon">
                        <i class="fa-brands fa-instagram"></i>
                    </span>

                    <span class="channel-copy">

                        <strong>
                            Instagram
                        </strong>

                        <small>
                            Social enquiries
                        </small>

                    </span>

                    <span class="check-mark">
                        <i class="fa-solid fa-check"></i>
                    </span>

                </label>

            </div>

        </section>


        <section class="activation-card">

            <div class="activation-icon">

                <i class="fa-solid fa-wand-magic-sparkles"></i>

            </div>

            <div>

                <span class="eyebrow">
                    YOUR AI WORKSPACE
                </span>

                <h2>
                    Your setup becomes
                    your AI's context.
                </h2>

                <p>
                    Later, OpsPilot will use these
                    details to answer customer questions,
                    understand your business and
                    automate repetitive work.
                </p>

            </div>

        </section>


        <div class="setup-actions">

            <span>
                You can change these settings anytime.
            </span>

            <button
                type="submit"
                class="primary-button"
            >

                Continue to dashboard

                <i class="fa-solid fa-arrow-right"></i>

            </button>

        </div>

    </form>

</main>


<script src="js/app.js"></script>

</body>

</html>