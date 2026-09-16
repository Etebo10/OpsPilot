<?php

require_once __DIR__ . '/../app/bootstrap.php';

if (isAuthenticated()) {
    redirect('dashboard.php');
}

$errors = [];

$name = '';
$email = '';
$business = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $name =
        trim(
            $_POST['name'] ?? ''
        );

    $email =
        strtolower(
            trim(
                $_POST['email'] ?? ''
            )
        );

    $business =
        trim(
            $_POST['business'] ?? ''
        );

    $password =
        $_POST['password'] ?? '';

    $confirmPassword =
        $_POST['confirm_password'] ?? '';


    if (
        $name === ''
    ) {
        $errors[] =
            'Please enter your full name.';
    }

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            'Please enter a valid email address.';
    }

    if (
        $business === ''
    ) {
        $errors[] =
            'Please enter your business name.';
    }

    if (
        strlen($password) < 8
    ) {
        $errors[] =
            'Password must contain at least 8 characters.';
    }

    if (
        $password !== $confirmPassword
    ) {
        $errors[] =
            'Passwords do not match.';
    }


    if (!$errors) {

        $pdo = db();

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Check email across organizations
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'SELECT id
                     FROM users
                     WHERE email = ?
                     LIMIT 1'
                );

            $stmt->execute([
                $email
            ]);

            if ($stmt->fetch()) {

                throw new RuntimeException(
                    'An account with this email already exists.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Organization
            |--------------------------------------------------------------------------
            */

            $slug =
                uniqueOrganizationSlug(
                    $business
                );

            $stmt =
                $pdo->prepare(
                    'INSERT INTO organizations
                    (
                        name,
                        slug
                    )
                    VALUES
                    (?, ?)'
                );

            $stmt->execute([
                $business,
                $slug
            ]);

            $organizationId =
                (int)
                $pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | Password
            |--------------------------------------------------------------------------
            */

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            /*
            |--------------------------------------------------------------------------
            | Owner
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'INSERT INTO users
                    (
                        organization_id,
                        name,
                        email,
                        password_hash,
                        role
                    )
                    VALUES
                    (?, ?, ?, ?, ?)'
                );

            $stmt->execute([
                $organizationId,
                $name,
                $email,
                $passwordHash,
                'owner'
            ]);

            $userId =
                (int)
                $pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | Default organization settings
            |--------------------------------------------------------------------------
            */

            $businessHours = json_encode([
                'monday' => [
                    'enabled' => true,
                    'open' => '09:00',
                    'close' => '17:00'
                ],
                'tuesday' => [
                    'enabled' => true,
                    'open' => '09:00',
                    'close' => '17:00'
                ],
                'wednesday' => [
                    'enabled' => true,
                    'open' => '09:00',
                    'close' => '17:00'
                ],
                'thursday' => [
                    'enabled' => true,
                    'open' => '09:00',
                    'close' => '17:00'
                ],
                'friday' => [
                    'enabled' => true,
                    'open' => '09:00',
                    'close' => '17:00'
                ],
                'saturday' => [
                    'enabled' => false,
                    'open' => '09:00',
                    'close' => '14:00'
                ],
                'sunday' => [
                    'enabled' => false,
                    'open' => '09:00',
                    'close' => '14:00'
                ]
            ]);

            $channels = json_encode([
                'phone' => true,
                'email' => true,
                'whatsapp' => false,
                'website' => true,
                'instagram' => false
            ]);

            $stmt =
                $pdo->prepare(
                    'INSERT INTO organization_settings
                    (
                        organization_id,
                        business_hours,
                        communication_channels
                    )
                    VALUES
                    (?, ?, ?)'
                );

            $stmt->execute([
                $organizationId,
                $businessHours,
                $channels
            ]);


            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | Login immediately
            |--------------------------------------------------------------------------
            */

            loginUser(
                $userId,
                $organizationId,
                'owner'
            );

            redirect(
                'onboarding.php'
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
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'We could not create your account. Please try again.';
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

    <title>Create your workspace — OpsPilot</title>

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

<body class="auth-page">

<div class="auth-layout">

    <section class="auth-visual">

        <a
            href="index.php"
            class="auth-logo"
        >

            <span class="brand-mark">
                OP
            </span>

            <span>
                OpsPilot
            </span>

        </a>

        <div class="auth-visual-content">

            <span class="eyebrow">
                THE BUSINESS OPERATING SYSTEM
            </span>

            <h1>
                Stop being the glue
                holding your business
                together.
            </h1>

            <p>
                OpsPilot connects customers,
                bookings, jobs, money and
                AI into one intelligent workspace.
            </p>

            <div class="visual-orbit">

                <div class="orbit-line orbit-one"></div>

                <div class="orbit-line orbit-two"></div>

                <div class="orbit-node node-one">
                    <i class="fa-solid fa-users"></i>
                </div>

                <div class="orbit-node node-two">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>

                <div class="orbit-node node-three">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </div>

                <div class="orbit-core">
                    ✦
                </div>

            </div>

        </div>

    </section>


    <main class="auth-panel">

        <div class="auth-form-container">

            <div class="auth-heading">

                <span class="eyebrow">
                    GET STARTED
                </span>

                <h2>
                    Build your workspace.
                </h2>

                <p>
                    Your business operating system
                    starts here.
                </p>

            </div>


            <?php if ($errors): ?>

                <div class="alert alert-error">

                    <i class="fa-solid fa-circle-exclamation"></i>

                    <div>

                        <?php foreach ($errors as $error): ?>

                            <div>
                                <?= e($error) ?>
                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                class="auth-form"
                novalidate
            >

                <?= csrf_field() ?>


                <div class="field">

                    <label for="business">
                        Business name
                    </label>

                    <input
                        id="business"
                        name="business"
                        type="text"
                        placeholder="Acme Studio"
                        value="<?= e($business) ?>"
                        autocomplete="organization"
                        required
                    >

                </div>


                <div class="field">

                    <label for="name">
                        Your name
                    </label>

                    <input
                        id="name"
                        name="name"
                        type="text"
                        placeholder="Alex Morgan"
                        value="<?= e($name) ?>"
                        autocomplete="name"
                        required
                    >

                </div>


                <div class="field">

                    <label for="email">
                        Work email
                    </label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        placeholder="alex@business.com"
                        value="<?= e($email) ?>"
                        autocomplete="email"
                        required
                    >

                </div>


                <div class="field">

                    <label for="password">
                        Password
                    </label>

                    <div class="password-field">

                        <input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="At least 8 characters"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-password-toggle="password"
                            aria-label="Show password"
                        >
                            <i class="fa-regular fa-eye"></i>
                        </button>

                    </div>

                </div>


                <div class="field">

                    <label for="confirm_password">
                        Confirm password
                    </label>

                    <input
                        id="confirm_password"
                        name="confirm_password"
                        type="password"
                        placeholder="Repeat your password"
                        autocomplete="new-password"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="primary-button full-button"
                >

                    Create workspace

                    <i class="fa-solid fa-arrow-right"></i>

                </button>

            </form>


            <p class="auth-switch">

                Already have an account?

                <a href="login.php">
                    Sign in
                </a>

            </p>

        </div>

    </main>

</div>


<script src="js/app.js"></script>

</body>

</html>