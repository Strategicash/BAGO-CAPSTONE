<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }
if ($_SESSION['tier'] !== 'premium') { header('Location: dashboard.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

// ── Fetch latest workout plan ─────────────────────────────────────────────────
$r = $conn->prepare("
    SELECT wp.*, u.full_name AS coach_name
    FROM workout_plans wp
    JOIN users u ON u.id = wp.coach_id
    WHERE wp.member_id = ?
    ORDER BY wp.created_at DESC
    LIMIT 1
");
$r->bind_param("i", $user_id);
$r->execute();
$plan = $r->get_result()->fetch_assoc();
$r->close();

// ── Plan history ─────────────────────────────────────────────────────────────
$r = $conn->prepare("
    SELECT wp.id, wp.plan_name, wp.phase, wp.duration_weeks, wp.created_at,
           u.full_name AS coach_name
    FROM workout_plans wp
    JOIN users u ON u.id = wp.coach_id
    WHERE wp.member_id = ?
    ORDER BY wp.created_at DESC
");
$r->bind_param("i", $user_id);
$r->execute();
$plan_history = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

// ── Nutrition plan ────────────────────────────────────────────────────────────
$r = $conn->prepare("
    SELECT np.*, u.full_name AS coach_name
    FROM nutrition_plans np
    JOIN users u ON u.id = np.coach_id
    WHERE np.member_id = ?
    ORDER BY np.created_at DESC
    LIMIT 1
");
$r->bind_param("i", $user_id);
$r->execute();
$nutrition = $r->get_result()->fetch_assoc();
$r->close();

// ── Workout logs: this week (Mon–today) ──────────────────────────────────────
// Fetch all logs grouped by day-of-week and muscle group for THIS week
$r = $conn->prepare("
    SELECT
        DAYOFWEEK(logged_at) AS dow,
        DATE(logged_at)      AS log_date,
        muscle_group,
        exercise,
        SUM(sets)            AS total_sets,
        SUM(reps)            AS total_reps,
        SUM(sets * reps * COALESCE(weight_kg,0)) AS total_volume,
        COUNT(*)             AS entries
    FROM workout_logs
    WHERE user_id = ?
      AND logged_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
    GROUP BY DATE(logged_at), muscle_group, exercise
    ORDER BY logged_at ASC
");
$r->bind_param("i", $user_id);
$r->execute();
$week_logs_raw = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

// ── All-time stats per muscle group ──────────────────────────────────────────
$r = $conn->prepare("
    SELECT
        muscle_group,
        COUNT(DISTINCT DATE(logged_at)) AS days_trained,
        SUM(sets)                        AS total_sets,
        MAX(weight_kg)                   AS max_weight,
        exercise
    FROM workout_logs
    WHERE user_id = ?
    GROUP BY muscle_group, exercise
    ORDER BY total_sets DESC
");
$r->bind_param("i", $user_id);
$r->execute();
$alltime_raw = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

// ── Today's logs ──────────────────────────────────────────────────────────────
$r = $conn->prepare("
    SELECT exercise, muscle_group, sets, reps, weight_kg, logged_at
    FROM workout_logs
    WHERE user_id = ? AND DATE(logged_at) = CURDATE()
    ORDER BY logged_at DESC
");
$r->bind_param("i", $user_id);
$r->execute();
$today_logs = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$conn->close();

// ── Build week log index: date → [muscle_groups logged] ─────────────────────
$week_by_date = [];
$week_by_dow  = []; // 1=Sun..7=Sat, we'll use 2=Mon..1=Sun
foreach ($week_logs_raw as $log) {
    $date = $log['log_date'];
    $mg   = strtolower($log['muscle_group'] ?? '');
    $week_by_date[$date][] = $log;
    $dow = (int)$log['dow']; // 1=Sun,2=Mon,...,7=Sat
    $week_by_dow[$dow][]   = $log;
}

// ── Calculate weeks elapsed ───────────────────────────────────────────────────
$weeks_done = 0;
$week_pct   = 0;
if ($plan) {
    $start      = new DateTime($plan['created_at']);
    $now        = new DateTime();
    $diff       = $start->diff($now);
    $weeks_done = min((int)floor($diff->days / 7), (int)$plan['duration_weeks']);
    $week_pct   = $plan['duration_weeks'] > 0
                  ? round(($weeks_done / $plan['duration_weeks']) * 100)
                  : 0;
}

// ── Today's day of week index (0=Mon … 6=Sun) ─────────────────────────────
$today_dow = (int)date('N') - 1; // 0=Mon,1=Tue,...,6=Sun

// ── Helper: normalize muscle group ───────────────────────────────────────────
function norm_muscle($s) {
    $s = strtolower(trim($s));
    if (str_contains($s,'chest'))                                             return 'chest';
    if (str_contains($s,'back') || str_contains($s,'lat') || str_contains($s,'row')) return 'back';
    if (str_contains($s,'bicep'))                                             return 'biceps';
    if (str_contains($s,'tricep'))                                            return 'triceps';
    if (str_contains($s,'shoulder') || str_contains($s,'delt') || str_contains($s,'press') && str_contains($s,'over')) return 'shoulders';
    if (str_contains($s,'leg') || str_contains($s,'quad') || str_contains($s,'hamstring') || str_contains($s,'glute') || str_contains($s,'squat')) return 'legs';
    if (str_contains($s,'core') || str_contains($s,'abs'))                   return 'core';
    if (str_contains($s,'cardio') || str_contains($s,'run') || str_contains($s,'bike')) return 'cardio';
    return $s ?: 'general';
}

function muscle_tag($word) {
    $w = strtolower($word);
    if (str_contains($w,'chest'))                                              return 'chest';
    if (str_contains($w,'back') || str_contains($w,'bicep') || str_contains($w,'lat')) return 'back';
    if (str_contains($w,'leg') || str_contains($w,'quad') || str_contains($w,'hamstring') || str_contains($w,'glute')) return 'legs';
    if (str_contains($w,'shoulder') || str_contains($w,'delt'))               return 'shoulder';
    return '';
}

// ── Parse coach notes into a structured daily schedule ────────────────────────
// Format coach can write in notes:
//   Day 1: Push - Chest, Shoulders, Triceps | Bench Press, OHP, Dips
//   Day 2: Pull - Back, Biceps | Deadlift, Rows, Curls
// OR just free text — we fall back to phase-based defaults
function parse_schedule_from_notes($notes, $phase) {
    $days = [];
    if ($notes) {
        // Try to detect "Day N:" pattern
        $lines = preg_split('/\r?\n/', $notes);
        foreach ($lines as $line) {
            if (preg_match('/day\s*(\d)/i', $line, $m)) {
                $daynum = (int)$m[1] - 1;
                // Extract exercises after |
                $exercises = [];
                if (str_contains($line, '|')) {
                    $ex_part = trim(explode('|', $line)[1]);
                    $exercises = array_map('trim', explode(',', $ex_part));
                }
                // Extract muscle groups from the description
                $desc_part = explode('|', $line)[0];
                $desc_part = preg_replace('/day\s*\d\s*:?\s*/i', '', $desc_part);
                $desc_part = trim($desc_part, " -:");
                $days[$daynum] = [
                    'name'      => $desc_part ?: 'Day ' . ($daynum+1),
                    'detail'    => $exercises ? implode(', ', $exercises) : 'Coach assigned',
                    'exercises' => $exercises,
                    'tags'      => array_map('norm_muscle', preg_split('/[,\-&]+/', $desc_part)),
                    'rest'      => stripos($line,'rest') !== false,
                ];
            }
        }
    }

    if (count($days) >= 4) {
        // Re-index to fill 7 days
        $result = [];
        for ($i = 0; $i < 7; $i++) {
            if (isset($days[$i])) {
                $result[] = $days[$i];
            } else {
                $result[] = ['name'=>'Rest / Recovery','detail'=>'Recovery day','exercises'=>[],'tags'=>[],'rest'=>true];
            }
        }
        return $result;
    }

    // ── Phase-based defaults ───────────────────────────────────────────────
    $p = strtolower($phase ?? '');

    if (str_contains($p,'push') || str_contains($p,'pull') || str_contains($p,'ppl')) {
        return [
            ['name'=>'Push — Chest, Shoulders, Triceps','detail'=>'Bench Press · OHP · Incline DB · Lateral Raise · Tricep Pushdown','exercises'=>['Bench Press','OHP','Incline DB Press','Lateral Raise','Tricep Pushdown'],'tags'=>['chest','shoulders','triceps'],'rest'=>false],
            ['name'=>'Pull — Back, Biceps','detail'=>'Deadlift · Pull-ups · Cable Row · Face Pull · Barbell Curl','exercises'=>['Deadlift','Pull-ups','Cable Row','Face Pull','Barbell Curl'],'tags'=>['back','biceps'],'rest'=>false],
            ['name'=>'Legs — Quads, Hamstrings, Glutes','detail'=>'Squat · Romanian DL · Leg Press · Leg Curl · Calf Raise','exercises'=>['Squat','Romanian Deadlift','Leg Press','Leg Curl','Calf Raise'],'tags'=>['legs'],'rest'=>false],
            ['name'=>'Rest / Active Recovery','detail'=>'Light cardio or mobility work','exercises'=>[],'tags'=>[],'rest'=>true],
            ['name'=>'Push — Chest, Shoulders, Triceps','detail'=>'Incline Bench · DB Shoulder Press · Cable Fly · Skull Crusher','exercises'=>['Incline Bench Press','DB Shoulder Press','Cable Fly','Skull Crusher'],'tags'=>['chest','shoulders','triceps'],'rest'=>false],
            ['name'=>'Pull — Back, Biceps','detail'=>'Pendlay Row · Lat Pulldown · Hammer Curl · Rear Delt Fly','exercises'=>['Pendlay Row','Lat Pulldown','Hammer Curl','Rear Delt Fly'],'tags'=>['back','biceps'],'rest'=>false],
            ['name'=>'Full Rest','detail'=>'Recovery day','exercises'=>[],'tags'=>[],'rest'=>true],
        ];
    } elseif (str_contains($p,'upper') || str_contains($p,'lower')) {
        return [
            ['name'=>'Upper Body A — Chest & Back','detail'=>'Bench Press · Barbell Row · Pull-ups · Dips','exercises'=>['Bench Press','Barbell Row','Pull-ups','Dips'],'tags'=>['chest','back'],'rest'=>false],
            ['name'=>'Lower Body A — Quads & Glutes','detail'=>'Squat · Leg Press · Lunges · Leg Extension','exercises'=>['Squat','Leg Press','Lunges','Leg Extension'],'tags'=>['legs'],'rest'=>false],
            ['name'=>'Rest / Active Recovery','detail'=>'Light cardio','exercises'=>[],'tags'=>[],'rest'=>true],
            ['name'=>'Upper Body B — Shoulders & Arms','detail'=>'OHP · Lateral Raise · Barbell Curl · Tricep Dip','exercises'=>['OHP','Lateral Raise','Barbell Curl','Tricep Dip'],'tags'=>['shoulders','biceps','triceps'],'rest'=>false],
            ['name'=>'Lower Body B — Hamstrings & Calves','detail'=>'Romanian DL · Leg Curl · Calf Raise · Hip Thrust','exercises'=>['Romanian Deadlift','Leg Curl','Calf Raise','Hip Thrust'],'tags'=>['legs'],'rest'=>false],
            ['name'=>'Full Body','detail'=>'Deadlift · Bench · Row · Squat variation','exercises'=>['Deadlift','Bench Press','Barbell Row','Goblet Squat'],'tags'=>['chest','back','legs'],'rest'=>false],
            ['name'=>'Full Rest','detail'=>'Recovery day','exercises'=>[],'tags'=>[],'rest'=>true],
        ];
    } elseif (str_contains($p,'strength')) {
        return [
            ['name'=>'Squat Day','detail'=>'Back Squat · Front Squat · Box Squat · Pause Squat','exercises'=>['Back Squat','Front Squat','Box Squat','Pause Squat'],'tags'=>['legs'],'rest'=>false],
            ['name'=>'Bench Press Day','detail'=>'Bench Press · Close Grip Bench · DB Press · Tricep Work','exercises'=>['Bench Press','Close Grip Bench','DB Press','Tricep Pushdown'],'tags'=>['chest','triceps'],'rest'=>false],
            ['name'=>'Rest','detail'=>'Full rest','exercises'=>[],'tags'=>[],'rest'=>true],
            ['name'=>'Deadlift Day','detail'=>'Conventional DL · Romanian DL · Good Morning · Back Extension','exercises'=>['Deadlift','Romanian Deadlift','Good Morning','Back Extension'],'tags'=>['back','legs'],'rest'=>false],
            ['name'=>'Overhead Press Day','detail'=>'OHP · Push Press · DB Lateral Raise · Face Pull','exercises'=>['OHP','Push Press','Lateral Raise','Face Pull'],'tags'=>['shoulders'],'rest'=>false],
            ['name'=>'Accessory Work','detail'=>'Isolation exercises for weak points','exercises'=>['Barbell Curl','Tricep Pushdown','Calf Raise','Ab Work'],'tags'=>[],'rest'=>false],
            ['name'=>'Full Rest','detail'=>'Recovery day','exercises'=>[],'tags'=>[],'rest'=>true],
        ];
    } else {
        // Default: Hypertrophy
        return [
            ['name'=>'Push — Chest, Shoulders, Triceps','detail'=>'Bench Press · Incline DB · Lateral Raise · OHP · Tricep Pushdown · Cable Fly','exercises'=>['Bench Press','Incline DB Press','Lateral Raise','OHP','Tricep Pushdown','Cable Fly'],'tags'=>['chest','shoulders','triceps'],'rest'=>false],
            ['name'=>'Pull — Back, Biceps','detail'=>'Deadlift · Pull-ups · Cable Row · Face Pull · Barbell Curl · Hammer Curl','exercises'=>['Deadlift','Pull-ups','Cable Row','Face Pull','Barbell Curl','Hammer Curl'],'tags'=>['back','biceps'],'rest'=>false],
            ['name'=>'Legs — Quads, Hamstrings, Glutes','detail'=>'Squat · Romanian DL · Leg Press · Leg Curl · Calf Raise','exercises'=>['Squat','Romanian Deadlift','Leg Press','Leg Curl','Calf Raise'],'tags'=>['legs'],'rest'=>false],
            ['name'=>'Rest / Active Recovery','detail'=>'Light cardio or mobility work','exercises'=>[],'tags'=>[],'rest'=>true],
            ['name'=>'Upper Body Strength','detail'=>'Bench Press · Weighted Pull-ups · DB Shoulder Press · Barbell Row · Dips','exercises'=>['Bench Press','Weighted Pull-ups','DB Shoulder Press','Barbell Row','Dips'],'tags'=>['chest','back','shoulders'],'rest'=>false],
            ['name'=>'Full Body','detail'=>'Deadlift · Bench · Squat · OHP · Pull-ups','exercises'=>['Deadlift','Bench Press','Squat','OHP','Pull-ups'],'tags'=>['legs','chest','back'],'rest'=>false],
            ['name'=>'Full Rest','detail'=>'Recovery day','exercises'=>[],'tags'=>[],'rest'=>true],
        ];
    }
}

$schedule = $plan ? parse_schedule_from_notes($plan['notes'] ?? '', $plan['phase'] ?? $plan['plan_name'] ?? '') : [];

// ── For each day, check if user logged matching muscles today/this week ───────
// Map: day_index (0=Mon) → logged muscle groups this week
// DAYOFWEEK: 1=Sun,2=Mon,...,7=Sat  →  0-indexed Mon: dow-2 (mod 7)
$logged_by_day = array_fill(0, 7, []);
foreach ($week_logs_raw as $log) {
    $dow = (int)$log['dow']; // 1=Sun..7=Sat
    // Convert to 0=Mon..6=Sun
    $day_idx = ($dow === 1) ? 6 : ($dow - 2);
    if ($day_idx >= 0 && $day_idx < 7) {
        $logged_by_day[$day_idx][] = $log;
    }
}

// ── Compute per-day progress ──────────────────────────────────────────────────
// "done" = at least one exercise logged that matches the day's muscle group
function day_has_progress($day_schedule, $day_logs) {
    if ($day_schedule['rest'] || empty($day_logs)) return false;
    $day_tags = array_map('norm_muscle', $day_schedule['tags']);
    foreach ($day_logs as $log) {
        $logged_mg = norm_muscle($log['muscle_group'] ?? '');
        $logged_ex = strtolower($log['exercise'] ?? '');
        // Match by muscle group
        foreach ($day_tags as $tag) {
            if ($tag && (str_contains($logged_mg, $tag) || str_contains($tag, $logged_mg))) return true;
            // Also match by exercise name keywords
            if ($tag === 'chest' && (str_contains($logged_ex,'bench') || str_contains($logged_ex,'chest') || str_contains($logged_ex,'fly') || str_contains($logged_ex,'push'))) return true;
            if ($tag === 'back'  && (str_contains($logged_ex,'row') || str_contains($logged_ex,'pull') || str_contains($logged_ex,'deadlift') || str_contains($logged_ex,'lat'))) return true;
            if ($tag === 'legs'  && (str_contains($logged_ex,'squat') || str_contains($logged_ex,'lunge') || str_contains($logged_ex,'leg') || str_contains($logged_ex,'deadlift') || str_contains($logged_ex,'calf'))) return true;
            if ($tag === 'shoulders' && (str_contains($logged_ex,'press') || str_contains($logged_ex,'lateral') || str_contains($logged_ex,'delt') || str_contains($logged_ex,'ohp'))) return true;
            if ($tag === 'biceps'   && (str_contains($logged_ex,'curl') || str_contains($logged_ex,'bicep'))) return true;
            if ($tag === 'triceps'  && (str_contains($logged_ex,'tricep') || str_contains($logged_ex,'pushdown') || str_contains($logged_ex,'dip') || str_contains($logged_ex,'skull'))) return true;
        }
        // If day has no tags, any log counts
        if (empty($day_tags) || $day_tags === ['']) return true;
    }
    return false;
}

// ── Summary counts ────────────────────────────────────────────────────────────
$workout_days_total  = 0;
$workout_days_done   = 0;
foreach ($schedule as $i => $day) {
    if (!$day['rest']) {
        $workout_days_total++;
        if (day_has_progress($day, $logged_by_day[$i])) $workout_days_done++;
    }
}

// Today's sets logged
$today_sets = array_sum(array_column($today_logs, 'sets'));
$today_exercises = count(array_unique(array_column($today_logs, 'exercise')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — My Plan</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
       --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #f59e0b; --accent-dim: rgba(245, 158, 11, 0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --red: #ef4444; --yellow: #f59e0b;
      --font: 'Plus Jakarta Sans', sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

    /* ── SIDEBAR ── */
    .sidebar { width: 210px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; }
    .sidebar-logo { padding: 18px 18px 14px; font-size: 15px; font-weight: 700; color: var(--text); border-bottom: 1px solid var(--border); }
    .sidebar-logo span { color: var(--accent); }
    .sidebar-user { padding: 14px 18px; border-bottom: 1px solid var(--border); }
    .user-av { width: 34px; height: 34px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; margin-bottom: 8px; }
    .user-name { font-size: 13px; font-weight: 600; margin-bottom: 3px; }
    .user-badge { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); }
    .sidebar-nav { flex: 1; padding: 10px 8px; overflow-y: auto; }
    .nav-lbl { font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--sub); padding: 0 10px; margin: 14px 0 5px; }
    .nav-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; transition: all 0.12s; margin-bottom: 1px; }
    .nav-item:hover { background: var(--card); color: var(--text); }
    .nav-item.active { background: var(--accent-dim); color: var(--accent); font-weight: 600; }
    .sidebar-foot { padding: 10px 8px; border-top: 1px solid var(--border); }
    .main { margin-left: 210px; flex: 1; }
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .content { padding: 24px 28px 60px; }

    /* ── CARDS ── */
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .cs { font-size: 11px; color: var(--sub); }

    /* ── PLAN HEADER ── */
    .plan-header { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ph-grid { display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: start; }
    .ph-name { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
    .ph-meta { font-size: 12px; color: var(--sub); margin-bottom: 12px; }
    .progress-bar { height: 5px; background: var(--border); border-radius: 3px; margin-bottom: 5px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 3px; background: var(--accent); transition: width 0.8s ease; }
    .progress-lbl { font-size: 11px; color: var(--sub); }
    .ph-week-box { text-align: center; background: var(--accent-dim); border: 1px solid rgba(59,130,246,0.2); border-radius: 8px; padding: 14px 20px; }
    .ph-week { font-size: 28px; font-weight: 700; color: var(--accent); line-height: 1; }
    .ph-week-lbl { font-size: 10px; color: var(--muted); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px; }

    /* ── WEEK STATS ROW ── */
    .week-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
    .wstat { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 14px 16px; }
    .wstat-val { font-size: 22px; font-weight: 700; margin-bottom: 2px; }
    .wstat-lbl { font-size: 11px; color: var(--sub); }
    .wstat.green .wstat-val { color: var(--green); }
    .wstat.blue  .wstat-val { color: var(--accent); }
    .wstat.yellow .wstat-val { color: var(--yellow); }

    /* ── DAILY SCHEDULE ── */
    .day-grid { display: flex; flex-direction: column; gap: 8px; }

    .day-row {
      display: grid;
      grid-template-columns: 36px 1fr auto;
      align-items: center;
      gap: 14px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 13px 16px;
      transition: border-color 0.15s, background 0.15s;
      position: relative;
      overflow: hidden;
    }
    .day-row.today { border-color: var(--accent); background: rgba(59,130,246,0.04); }
    .day-row.done  { border-color: var(--green);  background: rgba(34,197,94,0.04); }
    .day-row.rest  { opacity: 0.45; }
    .day-row.future { opacity: 0.7; }

    /* Glow stripe on left for done/today */
    .day-row.done::before  { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--green); border-radius:3px 0 0 3px; }
    .day-row.today::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--accent); border-radius:3px 0 0 3px; }

    .day-num-col { display: flex; flex-direction: column; align-items: center; gap: 2px; }
    .day-num { font-size: 10px; font-weight: 700; color: var(--sub); text-transform: uppercase; }
    .day-dow { font-size: 11px; font-weight: 700; color: var(--muted); }
    .day-row.today .day-dow { color: var(--accent); }
    .day-row.done  .day-dow { color: var(--green); }

    .day-info { min-width: 0; }
    .day-name { font-size: 13px; font-weight: 600; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .day-exercises { font-size: 11px; color: var(--sub); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    /* When done, show logged exercises */
    .day-logged { font-size: 11px; color: var(--green); margin-top: 2px; }

    .day-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .day-tags { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
    .tag { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 4px; }
    .tag.chest     { background: var(--red-dim);    color: var(--red); }
    .tag.back      { background: var(--green-dim);  color: var(--green); }
    .tag.legs      { background: var(--yellow-dim); color: var(--yellow); }
    .tag.shoulders { background: var(--purple-dim); color: var(--purple); }
    .tag.biceps    { background: var(--green-dim);  color: var(--green); }
    .tag.triceps   { background: var(--red-dim);    color: var(--red); }
    .tag.cardio    { background: var(--accent-dim); color: var(--accent); }
    .tag           { background: var(--border);     color: var(--muted); }

    /* Status badge */
    .status-badge { font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.3px; flex-shrink: 0; }
    .status-badge.done   { background: var(--green-dim);  color: var(--green); }
    .status-badge.today  { background: var(--accent-dim); color: var(--accent); }
    .status-badge.rest   { background: var(--border);     color: var(--sub); }
    .status-badge.upcoming { background: var(--border);   color: var(--sub); }

    /* Progress ring (mini) */
    .ring-wrap { position: relative; width: 36px; height: 36px; flex-shrink: 0; }
    .ring-wrap svg { transform: rotate(-90deg); }
    .ring-bg  { fill: none; stroke: var(--border); stroke-width: 3; }
    .ring-fill { fill: none; stroke-width: 3; stroke-linecap: round; transition: stroke-dashoffset 0.8s ease; }
    .ring-fill.done-ring  { stroke: var(--green); }
    .ring-fill.today-ring { stroke: var(--accent); }
    .ring-fill.rest-ring  { stroke: var(--border); }
    .ring-fill.future-ring { stroke: var(--border); }
    .ring-icon { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 13px; }

    /* ── LOG QUICK PANEL (today) ── */
    .today-panel { background: rgba(59,130,246,0.04); border: 1px solid rgba(59,130,246,0.15); border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; }
    .today-panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .today-panel-title { font-size: 13px; font-weight: 700; color: var(--accent); }
    .today-log-list { display: flex; flex-direction: column; gap: 6px; }
    .today-log-item { display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--muted); background: var(--surface); border-radius: 5px; padding: 7px 10px; }
    .today-log-item .ex-name { font-weight: 600; color: var(--text); flex: 1; }
    .today-log-item .ex-detail { color: var(--sub); font-size: 11px; }
    .today-none { font-size: 12px; color: var(--sub); padding: 4px 0; }

    .log-btn { display: inline-flex; align-items: center; gap: 5px; background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 6px 13px; font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: opacity 0.15s; }
    .log-btn:hover { opacity: 0.85; }

    /* ── MACRO CARDS ── */
    .macro-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; margin-bottom: 14px; }
    .macro-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .macro-card .m-val { font-size: 22px; font-weight: 700; margin-bottom: 3px; }
    .macro-card .m-lbl { font-size: 10px; color: var(--sub); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .macro-card.calories .m-val { color: var(--accent); }
    .macro-card.protein  .m-val { color: var(--green); }
    .macro-card.carbs    .m-val { color: var(--yellow); }
    .macro-card.fats     .m-val { color: var(--red); }

    /* ── PLAN HISTORY TABLE ── */
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 10px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
    .pill.active { background: var(--green-dim); color: var(--green); }
    .pill.old    { background: var(--border); color: var(--sub); }

    /* ── EMPTY STATE ── */
    .empty { text-align: center; padding: 40px 20px; color: var(--sub); font-size: 13px; }
    .empty a { color: var(--accent); text-decoration: none; font-weight: 600; }

    @media (max-width: 900px) { .macro-grid { grid-template-columns: 1fr 1fr; } .week-stats { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; } }
  </style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">FitX <span>Training Club</span></div>
  <div class="sidebar-user">
    <div class="user-av"><?= $avatar ?></div>
    <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
    <div class="user-badge"><?= ucfirst($tier) ?></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-lbl">Main</div>
    <a href="dashboard.php"    class="nav-item">📊 Dashboard</a>
    <a href="workouts.php"     class="nav-item">🏋️ Workouts</a>
    <a href="progress.php"     class="nav-item">📈 Progress</a>
    <a href="body_metrics.php" class="nav-item">⚖️ Body Metrics</a>
    <?php if ($tier === 'premium'): ?>
    <div class="nav-lbl">Premium</div>
    <a href="my_coach.php" class="nav-item">👤 My Coach</a>
    <a href="my_plan.php"  class="nav-item active">📋 My Plan</a>
    <a href="messages.php" class="nav-item">💬 Messages</a>
    <?php endif; ?>
    <div class="nav-lbl">Account</div>
    <a href="profile.php" class="nav-item">⚙️ Profile</a>
    <a href="billing.php" class="nav-item">💳 Billing</a>
  </nav>
  <div class="sidebar-foot"><a href="logout.php" class="nav-item">🚪 Log Out</a></div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">My Plan</div>
    <span style="font-size:12px;color:var(--sub)">
      <?= $plan ? 'By '.htmlspecialchars($plan['coach_name']) : 'No plan assigned yet' ?>
    </span>
  </div>

  <div class="content">

    <?php if (!$plan): ?>
    <div class="empty">
      <div style="font-size:32px;margin-bottom:12px;">📋</div>
      <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:8px;">No workout plan yet</div>
      <div style="margin-bottom:16px;">Your coach hasn't assigned a plan yet. Check back soon or send them a message.</div>
      <a href="messages.php">Message your coach →</a>
    </div>

    <?php else: ?>

    <!-- ── PLAN HEADER ── -->
    <div class="plan-header">
      <div class="ph-grid">
        <div>
          <div class="ph-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
          <div class="ph-meta">
            <?= $plan['duration_weeks'] ?> weeks
            <?php if ($plan['phase']): ?> · <?= htmlspecialchars($plan['phase']) ?><?php endif; ?>
            · Started <?= date('M j, Y', strtotime($plan['created_at'])) ?>
            · Coach: <?= htmlspecialchars($plan['coach_name']) ?>
          </div>
          <div class="progress-bar" style="width:min(280px,100%);">
            <div class="progress-fill" style="width:<?= $week_pct ?>%"></div>
          </div>
          <div class="progress-lbl"><?= $weeks_done ?> of <?= $plan['duration_weeks'] ?> weeks completed (<?= $week_pct ?>%)</div>
        </div>
        <div class="ph-week-box">
          <div class="ph-week">W<?= min(max(1,$weeks_done+1), $plan['duration_weeks']) ?></div>
          <div class="ph-week-lbl">Current Week</div>
        </div>
      </div>
    </div>

    <!-- ── THIS WEEK STATS ── -->
    <div class="week-stats">
      <div class="wstat green">
        <div class="wstat-val"><?= $workout_days_done ?>/<?= $workout_days_total ?></div>
        <div class="wstat-lbl">Days Completed This Week</div>
      </div>
      <div class="wstat blue">
        <div class="wstat-val"><?= $today_exercises ?></div>
        <div class="wstat-lbl">Exercises Logged Today</div>
      </div>
      <div class="wstat yellow">
        <div class="wstat-val"><?= $today_sets ?></div>
        <div class="wstat-lbl">Sets Logged Today</div>
      </div>
    </div>

    <!-- ── TODAY'S LOGGED WORKOUTS ── -->
    <?php
    $today_day = $schedule[$today_dow] ?? null;
    $is_rest_today = $today_day && $today_day['rest'];
    ?>
    <?php if (!$is_rest_today): ?>
    <div class="today-panel">
      <div class="today-panel-head">
        <div class="today-panel-title">
          📍 Today — <?= date('l, M j') ?>
          <?php if ($today_day): ?> · <?= htmlspecialchars($today_day['name']) ?><?php endif; ?>
        </div>
        <a href="dashboard.php#log" class="log-btn">+ Log Workout</a>
      </div>
      <?php if (empty($today_logs)): ?>
        <div class="today-none">No workouts logged yet today. Hit the gym! 💪</div>
      <?php else: ?>
        <div class="today-log-list">
          <?php foreach ($today_logs as $tl): ?>
          <div class="today-log-item">
            <span class="ex-name"><?= htmlspecialchars($tl['exercise']) ?></span>
            <span class="ex-detail">
              <?= $tl['sets'] ?>×<?= $tl['reps'] ?>
              <?php if ($tl['weight_kg'] > 0): ?>@ <?= $tl['weight_kg'] ?>kg<?php endif; ?>
              <?php if ($tl['muscle_group']): ?>· <?= htmlspecialchars($tl['muscle_group']) ?><?php endif; ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── COACH NOTES ── -->
    <?php if ($plan['notes']): ?>
    <div class="card">
      <div class="ch">
        <div class="ct">Coach Notes</div>
        <span class="cs">From <?= htmlspecialchars($plan['coach_name']) ?></span>
      </div>
      <p style="font-size:13px;color:var(--muted);line-height:1.9;"><?= nl2br(htmlspecialchars($plan['notes'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- ── WEEKLY SCHEDULE ── -->
    <?php
    $dow_labels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $circumference = 2 * M_PI * 14; // r=14
    ?>
    <div class="card">
      <div class="ch">
        <div class="ct">Weekly Schedule</div>
        <span class="cs">
          <?= $plan['phase'] ? htmlspecialchars($plan['phase']) : 'General' ?>
          · <?= $workout_days_done ?>/<?= $workout_days_total ?> done this week
        </span>
      </div>
      <div class="day-grid">
        <?php foreach ($schedule as $i => $day):
          $is_today   = ($i === $today_dow);
          $is_past    = ($i < $today_dow);
          $day_logs_i = $logged_by_day[$i];
          $is_done    = !$day['rest'] && day_has_progress($day, $day_logs_i);
          $logged_sets = array_sum(array_column($day_logs_i, 'total_sets'));
          $logged_ex_names = array_unique(array_column($day_logs_i, 'exercise'));

          // Ring progress: cap at 100%
          // Target: assume ~20 sets per workout day (rough)
          $target_sets = 20;
          $ring_pct    = $day['rest'] ? 0 : min(1, $logged_sets / $target_sets);
          $dash_offset = $circumference * (1 - $ring_pct);

          // CSS class for the row
          $row_class = 'day-row';
          if ($day['rest'])      $row_class .= ' rest';
          elseif ($is_done)      $row_class .= ' done';
          elseif ($is_today)     $row_class .= ' today';
          elseif ($is_past)      $row_class .= ' future'; // past but not done
          else                   $row_class .= ' future'; // upcoming

          // Ring color class
          $ring_class = 'ring-fill ';
          $ring_class .= $day['rest'] ? 'rest-ring' : ($is_done ? 'done-ring' : ($is_today ? 'today-ring' : 'future-ring'));

          // Status badge
          if ($day['rest']) { $badge='rest';     $badge_lbl='Rest'; }
          elseif ($is_done) { $badge='done';     $badge_lbl='✓ Done'; }
          elseif ($is_today){ $badge='today';    $badge_lbl='Today'; }
          else              { $badge='upcoming'; $badge_lbl=$dow_labels[$i]; }

          // Ring icon
          if ($day['rest'])       $ring_icon = '😴';
          elseif ($is_done)       $ring_icon = '✓';
          elseif ($is_today)      $ring_icon = '▶';
          else                    $ring_icon = '';
        ?>
        <div class="<?= $row_class ?>">

          <!-- Progress ring -->
          <div class="ring-wrap">
            <svg width="36" height="36" viewBox="0 0 36 36">
              <circle class="ring-bg"   cx="18" cy="18" r="14"/>
              <circle class="<?= $ring_class ?>"
                cx="18" cy="18" r="14"
                stroke-dasharray="<?= round($circumference, 2) ?>"
                stroke-dashoffset="<?= $day['rest'] ? $circumference : round($dash_offset, 2) ?>"
              />
            </svg>
            <div class="ring-icon"><?= $ring_icon ?></div>
          </div>

          <!-- Day info -->
          <div class="day-info">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
              <span style="font-size:10px;font-weight:700;color:var(--sub);text-transform:uppercase;"><?= $dow_labels[$i] ?></span>
              <span class="day-name" style="margin:0;"><?= htmlspecialchars($day['name']) ?></span>
            </div>
            <?php if ($is_done && !empty($logged_ex_names)): ?>
<div class="day-logged">✓ <?= htmlspecialchars(implode(', ', array_slice($logged_ex_names, 0, 3))) ?><?= count($logged_ex_names) > 3 ? ' +' . (count($logged_ex_names) - 3) . ' more' : '' ?> · <?= $logged_sets ?> sets</div>            <?php elseif ($is_today && !empty($today_logs)): ?>
              <div class="day-logged" style="color:var(--accent)">In progress · <?= $today_sets ?> sets logged</div>
            <?php elseif ($is_today): ?>
              <div class="day-exercises"><?= htmlspecialchars($day['detail']) ?></div>
            <?php else: ?>
              <div class="day-exercises"><?= htmlspecialchars($day['detail']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Tags + badge -->
          <div class="day-right">
            <?php if (!empty($day['tags']) && !$day['rest']): ?>
            <div class="day-tags">
              <?php foreach (array_slice($day['tags'], 0, 2) as $tag):
                $tc = muscle_tag($tag); ?>
                <span class="tag <?= $tc ?>"><?= htmlspecialchars(ucfirst($tag)) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <span class="status-badge <?= $badge ?>"><?= $badge_lbl ?></span>
          </div>

        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── NUTRITION PLAN ── -->
    <?php if ($nutrition): ?>
    <div class="card">
      <div class="ch">
        <div class="ct">Nutrition Plan</div>
        <span class="cs">By <?= htmlspecialchars($nutrition['coach_name']) ?> · <?= date('M j, Y', strtotime($nutrition['created_at'])) ?></span>
      </div>
      <div style="margin-bottom:14px;">
        <div style="font-size:15px;font-weight:700;margin-bottom:4px;"><?= htmlspecialchars($nutrition['plan_name']) ?></div>
        <div style="font-size:12px;color:var(--sub);">Goal: <?= htmlspecialchars($nutrition['goal']) ?> · <?= $nutrition['meals_per_day'] ?> meals/day</div>
      </div>
      <div class="macro-grid">
        <div class="macro-card calories"><div class="m-val"><?= $nutrition['calories'] ?: '—' ?></div><div class="m-lbl">Calories</div></div>
        <div class="macro-card protein"> <div class="m-val"><?= $nutrition['protein_g']  ? $nutrition['protein_g'].'g'  : '—' ?></div><div class="m-lbl">Protein</div></div>
        <div class="macro-card carbs">   <div class="m-val"><?= $nutrition['carbs_g']    ? $nutrition['carbs_g'].'g'    : '—' ?></div><div class="m-lbl">Carbs</div></div>
        <div class="macro-card fats">    <div class="m-val"><?= $nutrition['fats_g']     ? $nutrition['fats_g'].'g'     : '—' ?></div><div class="m-lbl">Fats</div></div>
      </div>
      <?php if ($nutrition['notes']): ?>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:8px;">Meal Guide</div>
        <p style="font-size:13px;color:var(--muted);line-height:1.8;"><?= nl2br(htmlspecialchars($nutrition['notes'])) ?></p>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="ch"><div class="ct">Nutrition Plan</div></div>
      <p style="font-size:13px;color:var(--sub);">No nutrition plan assigned yet. <a href="messages.php" style="color:var(--accent);text-decoration:none;font-weight:600;">Ask your coach →</a></p>
    </div>
    <?php endif; ?>

    <!-- ── PLAN HISTORY ── -->
    <?php if (count($plan_history) > 1): ?>
    <div class="card">
      <div class="ch">
        <div class="ct">Plan History</div>
        <span class="cs"><?= count($plan_history) ?> plans total</span>
      </div>
      <table>
        <thead><tr><th>Plan Name</th><th>Phase</th><th>Duration</th><th>Coach</th><th>Assigned</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($plan_history as $i => $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['plan_name']) ?></td>
            <td><?= $p['phase'] ? htmlspecialchars($p['phase']) : '—' ?></td>
            <td><?= $p['duration_weeks'] ?> weeks</td>
            <td><?= htmlspecialchars($p['coach_name']) ?></td>
            <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
            <td><span class="pill <?= $i === 0 ? 'active' : 'old' ?>"><?= $i === 0 ? 'Active' : 'Past' ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php endif; // end plan exists ?>
  </div><!-- /content -->
</main>
</body>
</html>