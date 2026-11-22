<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * The few trip functions every public page needs (the header button, "Add to trip"). Kept apart from trips.php
 * so the shared layout does not load the whole booking engine; see trips.php for how a trip works.
 */

// ---------- The trip in the session ----------
function trip_items(): array
{
    $items = $_SESSION['trip']['items'] ?? [];
    return is_array($items) ? array_values($items) : [];
}

function trip_count(): int
{
    return count(trip_items());
}

function trip_save(array $items): void
{
    $_SESSION['trip']['items'] = array_values($items);
}

function trip_clear(): void
{
    unset($_SESSION['trip']);
}

function trip_remove(string $id): bool
{
    $items = trip_items();
    $left  = array_values(array_filter($items, fn($i) => ($i['id'] ?? '') !== $id));
    trip_save($left);
    return count($left) !== count($items);
}

// ---------- Buttons ----------
/**
 * "Add to trip" next to a "Reserve" button inside the same form. Reserve is a GET form that goes to checkout.php;
 * this button posts the same fields to trip_add.php instead. The CSRF token travels on the button itself, so it
 * is sent only when this button is used (and never ends up in the Reserve link).
 */
function trip_add_button(): string
{
    return '<button class="btn" type="submit" formmethod="post" formaction="trip_add.php" name="csrf" value="' . h(csrf_token()) . '">Add to trip</button>';
}

/** Header button with the number of stays in the trip. */
function trip_header_link(): string
{
    if (empty($_SESSION['guest_id'])) {
        return '';   // a trip needs an account, so signed-out visitors don't see the button
    }
    $n = trip_count();
    return '<a class="trip-btn" href="trip.php" title="Your trip" aria-label="' . h($n > 0 ? 'Your trip, ' . plural($n, 'stay') : 'Your trip') . '">'
         . icon('trip') . '<span class="trip-label">Trip</span>'
         . ($n > 0 ? '<span class="trip-count" aria-hidden="true">' . $n . '</span>' : '') . '</a>';
}
