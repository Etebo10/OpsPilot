<?php

require_once __DIR__ . '/../app/bootstrap.php';

if (isAuthenticated()) {
    redirect('dashboard.php');
}

$errors = [];

$email = '';

$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $email =
        strtolower(
            trim(
                $_POST['email'] ?? ''
            )
        );

    $password =
        $_POST['password'] ?? '';


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
        $password === ''
    ) {

        $errors[] =
            'Please enter your password.';
    }


    if (!$errors) {

        $pdo ??= db();

        /*
        |--------------------------------------------------------------------------
        | Simple login throttling
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare(
                'SELECT COUNT(*)
                 FROM login_attempts
                 WHERE email = ?
                 AND ip_address = ?
                 AND attempted_at >=
                     DATE_SUB(
                         NOW(),
                         INTERVAL 15 MINUTE
                     )'
            );

        $stmt->execute([
            $email,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);

        $attempts =
            (int)
            $stmt->fetchColumn();

        if ($attempts >= 8) {

            $errors[] =
                'Too many login attempts. Please wait a few minutes and try again.';
        }
    }


    if (!$errors) {

        $pdo ??= db();

        $stmt =
            $pdo->prepare(
                'SELECT
                    id,
                    organization_id,
                    name,
                    email,
                    password_hash,
                    role,
                    is_active
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );

        $stmt->execute([
            $email
        ]);

        $user =
            $stmt->fetch();


        if (
            $user
            &&
            $user['is_active']
            &&
            password_verify(
                $password,
                $user['password_hash']
            )
        ) {

            /*
            |--------------------------------------------------------------------------
            | Upgrade password hash when needed
            |--------------------------------------------------------------------------
            */

            if (
                password_needs_rehash(
                    $user['password_hash'],
                    PASSWORD_DEFAULT
                )
            ) {

                $newHash =
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                $stmt =
                    $pdo->prepare(
                        'UPDATE users
                         SET password_hash = ?
                         WHERE id = ?'
                    );

                $stmt->execute([
                    $newHash,
                    $user['id']
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Clear login attempts
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'DELETE FROM login_attempts
                     WHERE email = ?
                     AND ip_address = ?'
                );

            $stmt->execute([
                $email,
                $_SERVER['REMOTE_ADDR']
                    ?? '0.0.0.0'
            ]);


            /*
            |--------------------------------------------------------------------------
            | Update login timestamp
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'UPDATE users
                     SET last_login_at = NOW()
                     WHERE id = ?'
                );

            $stmt->execute([
                $user['id']
            ]);


            loginUser(
                (int) $user['id'],
                (int) $user['organization_id'],
                $user['role']
            );


            redirect(
                'dashboard.php'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Record failed attempt
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    'INSERT INTO login_attempts
                    (
                        email,
                        ip_address
                    )
                    VALUES
                    (?, ?)'
                );

            $stmt->execute([
                $email,
                $_SERVER['REMOTE_ADDR']
                    ?? '0.0.0.0'
            ]);

            $errors[] =
                'The email or password you entered is incorrect.';
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

    <title>Sign in — OpsPilot</title>

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
                YOUR BUSINESS. CONNECTED.
            </span>

            <h1>
                Everything happening
                in your business.
                One place.
            </h1>

            <p>
                Customers, conversations,
                bookings, jobs, invoices
                and intelligence — connected.
            </p>

            <div class="floating-stat">

                <span class="floating-stat-icon">
                    <i class="fa-solid fa-arrow-trend-up"></i>
                </span>

                <div>

                    <small>
                        BUSINESS PULSE
                    </small>

                    <strong>
                        +18.4% revenue
                    </strong>

                </div>

            </div>

        </div>

    </section>


    <main class="auth-panel">

        <div class="auth-form-container">

            <div class="auth-heading">

                <span class="eyebrow">
                    WELCOME BACK
                </span>

                <h2>
                    Good to see you.
                </h2>

                <p>
                    Sign in to your workspace.
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


            <?php if (
                isset($_GET['expired'])
            ): ?>

                <div class="alert alert-warning">

                    <i class="fa-solid fa-clock"></i>

                    Your session expired.
                    Please sign in again.

                </div>

            <?php endif; ?>


            <form
                method="POST"
                class="auth-form"
            >

                <?= csrf_field() ?>


                <div class="field">

                    <label for="email">
                        Email
                    </label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        placeholder="you@business.com"
                        value="<?= e($email) ?>"
                        autocomplete="email"
                        required
                        autofocus
                    >

                </div>


                <div class="field">

                    <div class="field-label-row">

                        <label for="password">
                            Password
                        </label>

                        <a
                            href="#"
                            class="muted-link"
                            onclick="return false;"
                        >
                            Forgot password?
                        </a>

                    </div>


                    <div class="password-field">

                        <input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="Your password"
                            autocomplete="current-password"
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


                <button
                    type="submit"
                    class="primary-button full-button"
                >

                    Sign in

                    <i class="fa-solid fa-arrow-right"></i>

                </button>

            </form>


            <p class="auth-switch">

                Don't have an account?

                <a href="register.php">
                    Create one
                </a>

            </p>

        </div>

    </main>

</div>


<script src="js/app.js"></script>

</body>

</html>