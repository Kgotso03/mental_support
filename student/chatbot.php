<?php
/**
 * Student Mental Health Chatbot API Endpoint
 * Comprehensive knowledge base covering symptoms, coping, treatment, crisis resources and more.
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized student access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$question = isset($input['question']) ? trim($input['question']) : '';

if (empty($question)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Question is required']);
    exit;
}

// =============================================================
//  COMPREHENSIVE MENTAL HEALTH KNOWLEDGE BASE
// =============================================================
$knowledgeBase = [

    // ──────────────────────────────────────────────
    // ANXIETY DISORDERS
    // ──────────────────────────────────────────────
    'anxiety_general' => [
        'keywords' => ['anxiety', 'anxious', 'worry', 'worried', 'worrying', 'nervousness', 'nervous', 'fear', 'apprehension', 'dread', 'unease'],
        'answer' => "### Understanding Anxiety

Anxiety is your body's natural alarm system — a response to perceived threat or stress. In short bursts it can be helpful (it keeps us alert), but when it becomes chronic or overwhelming it interferes with daily life.

**Common Symptoms**
* Racing or pounding heart
* Shortness of breath or feeling like you can't breathe properly
* Restlessness, fidgeting, inability to sit still
* Muscle tension, headaches, trembling
* Excessive or uncontrollable worrying
* Difficulty concentrating — mind goes blank
* Irritability or feeling on edge
* Sleep disturbances (trouble falling or staying asleep)
* Nausea, stomach upset, digestive issues
* Sweating or hot flushes

**Types of Anxiety Disorders**
* *Generalised Anxiety Disorder (GAD)* — persistent, excessive worry about many areas of life
* *Social Anxiety Disorder* — intense fear of social situations or being judged
* *Panic Disorder* — recurrent unexpected panic attacks
* *Specific Phobias* — intense fear of a specific object or situation
* *Separation Anxiety* — fear of being away from a specific person

**Quick Coping: Box Breathing**
1. Inhale slowly for **4 seconds**
2. Hold for **4 seconds**
3. Exhale completely for **4 seconds**
4. Pause empty for **4 seconds**
Repeat 4–5 times. This resets your nervous system's fight-or-flight response."
    ],

    'panic_attacks' => [
        'keywords' => ['panic', 'panic attack', 'heart racing', 'cant breathe', 'can\'t breathe', 'hyperventilating', 'chest tight', 'chest pain', 'dizzy', 'dizziness', 'faint', 'fainting'],
        'answer' => "### Panic Attacks — What They Are & What To Do

A panic attack is a sudden surge of intense fear or discomfort that peaks within minutes. They feel terrifying but are **not dangerous** — you will not die or go crazy from one.

**Symptoms During a Panic Attack**
* Pounding or racing heart (palpitations)
* Shortness of breath, feeling of choking
* Chest pain or pressure
* Sweating, trembling, chills or hot flushes
* Nausea or stomach distress
* Dizziness, unsteadiness or faintness
* Numbness or tingling in hands and feet
* Feeling of unreality (derealisation) or detachment from yourself (depersonalisation)
* Fear of losing control, going crazy, or dying

**In the Moment — What to Do**
1. **Remind yourself:** *\"This is a panic attack. It will pass. I am safe.\"*
2. **Breathe slowly** — Breathe in for 4 counts, out for 6 counts. A longer exhale activates your parasympathetic system.
3. **Ground yourself with 5-4-3-2-1** (see Grounding topic)
4. **Cold water** — Splash cold water on your face or hold ice. This triggers the dive reflex, slowing your heart rate rapidly.
5. **Don't flee** — Leaving reinforces fear. Stay in the situation if it is safe to do so.

**After the Attack**
* Rest, drink water, and be gentle with yourself.
* Note what triggered it if you can — patterns help therapists create a treatment plan."
    ],

    'social_anxiety' => [
        'keywords' => ['social anxiety', 'social fear', 'shy', 'shyness', 'embarrassed', 'embarrassment', 'judged', 'judgment', 'public speaking', 'crowds', 'speaking up', 'meeting people', 'social situations'],
        'answer' => "### Social Anxiety Disorder

Social anxiety goes far beyond shyness. It is an intense, persistent fear of social situations where you might be watched, judged, embarrassed, or humiliated.

**Common Triggers**
* Meeting new people
* Public speaking or presenting
* Being watched while eating or writing
* Entering a room where people are already seated
* Making phone calls or eye contact
* Parties, classes, queues

**Physical Symptoms in Social Situations**
* Blushing, sweating, trembling
* Rapid heart rate
* Nausea, voice trembling
* Difficulty speaking, going blank
* Mind racing with self-critical thoughts

**Helpful Strategies**
* **Challenge the thought:** Ask *\"What is the realistic evidence that people are judging me?\"*
* **Behavioural experiments:** Test your feared outcome — usually it doesn't happen.
* **Gradual exposure:** Start small (greet a classmate) and slowly face bigger situations.
* **Focus outward:** Concentrate on the conversation, not on yourself.
* **Cognitive Behavioral Therapy (CBT)** is the gold standard treatment for social anxiety."
    ],

    // ──────────────────────────────────────────────
    // DEPRESSION
    // ──────────────────────────────────────────────
    'depression' => [
        'keywords' => ['depression', 'depressed', 'sad', 'sadness', 'hopeless', 'hopelessness', 'empty', 'worthless', 'meaningless', 'no motivation', 'unmotivated', 'crying', 'tearful', 'low mood', 'feel nothing', 'numb', 'disconnected'],
        'answer' => "### Understanding Depression

Depression (Major Depressive Disorder) is more than just feeling sad. It is a serious medical condition that changes how you think, feel, and function — affecting sleep, appetite, energy, and self-worth.

**Symptoms (most days, for 2+ weeks)**
* Persistent sad, empty, or hopeless mood
* Loss of interest or pleasure in activities you used to enjoy (anhedonia)
* Significant changes in appetite or weight (increase or decrease)
* Sleeping too much or inability to sleep
* Fatigue and loss of energy — even small tasks feel exhausting
* Feelings of worthlessness or excessive guilt
* Difficulty thinking, concentrating, or making decisions
* Slowed movement or speech (visible to others)
* Recurrent thoughts of death, dying, or suicide

**What Actually Helps**
* **Behavioural Activation:** Do one small thing you used to enjoy — even if you don't feel like it. Action precedes motivation, not the other way around.
* **Routine:** A consistent sleep and wake time is one of the most powerful tools.
* **Physical movement:** Even a 10-minute walk increases serotonin, dopamine, and endorphins.
* **Social connection:** Reach out to one person today. Depression thrives in isolation.
* **Professional support:** CBT and antidepressants are both highly effective; combined they work even better.

**What Not to Do**
* Don't wait until you \"feel ready\" to seek help — depression makes you feel like nothing will work.
* Don't isolate completely.
* Avoid using alcohol or substances to cope — they worsen depression long-term."
    ],

    'seasonal_depression' => [
        'keywords' => ['seasonal depression', 'sad', 'winter depression', 'seasonal', 'winter blues', 'sunlight', 'sun', 'light therapy'],
        'answer' => "### Seasonal Affective Disorder (SAD)

SAD is a type of depression that follows a seasonal pattern — most commonly appearing in autumn/winter and lifting in spring/summer.

**Symptoms**
* Low mood, hopelessness, guilt
* Oversleeping (hypersomnia)
* Increased appetite, particularly for carbohydrates
* Weight gain
* Social withdrawal, loss of interest in activities

**Evidence-Based Treatments**
* **Light therapy:** Sitting in front of a 10,000 lux light box for 20–30 minutes each morning is highly effective.
* **Vitamin D supplementation:** Deficiency is linked to seasonal mood changes.
* **Regular outdoor time:** Even on cloudy days, natural light helps regulate circadian rhythms.
* **CBT for SAD:** A specialised version of CBT that targets seasonal patterns.
* **Antidepressants:** SSRIs are commonly prescribed for moderate-to-severe SAD."
    ],

    // ──────────────────────────────────────────────
    // BIPOLAR DISORDER
    // ──────────────────────────────────────────────
    'bipolar' => [
        'keywords' => ['bipolar', 'manic', 'mania', 'mood swings', 'mood episodes', 'highs and lows', 'grandiose', 'elation', 'racing thoughts'],
        'answer' => "### Bipolar Disorder

Bipolar disorder involves dramatic shifts in mood, energy, and activity levels — from very high/elated (mania or hypomania) to very low (depression).

**Types**
* *Bipolar I* — Full manic episodes lasting at least 7 days, often with depressive episodes
* *Bipolar II* — Hypomanic (milder) episodes plus depressive episodes
* *Cyclothymia* — Milder mood cycling over at least 2 years

**Manic Episode Symptoms**
* Unusually elevated, expansive, or irritable mood
* Decreased need for sleep (feel rested after 3 hours)
* Grandiosity or inflated self-esteem
* Racing thoughts, rapid speech, flight of ideas
* Distractibility, can't focus
* Increased goal-directed activity or psychomotor agitation
* Impulsive, risky behaviour (spending sprees, reckless driving, sexual indiscretions)

**Depressive Episode Symptoms** — Same as Major Depression (see Depression topic)

**Management Strategies**
* **Mood stabilisers** (e.g., lithium, valproate) are the cornerstone of treatment
* **Sleep regulation is critical** — irregular sleep can trigger episodes
* **Keep a mood diary** to identify triggers and early warning signs
* **Avoid alcohol and recreational drugs** — they destabilise mood significantly
* **Psychoeducation** helps you and your family understand the condition"
    ],

    // ──────────────────────────────────────────────
    // PTSD & TRAUMA
    // ──────────────────────────────────────────────
    'ptsd' => [
        'keywords' => ['ptsd', 'trauma', 'traumatic', 'flashback', 'flashbacks', 'nightmares', 'triggered', 'triggers', 'hypervigilant', 'hypervigilance', 'abuse', 'assault', 'accident', 'disaster'],
        'answer' => "### PTSD & Trauma

Post-Traumatic Stress Disorder (PTSD) can develop after experiencing or witnessing a traumatic event. It is not a sign of weakness — it is the brain's attempt to protect you from future harm.

**Symptoms (grouped into 4 clusters)**

1. **Re-experiencing**
   * Intrusive memories or flashbacks (feeling like you are reliving the event)
   * Nightmares
   * Intense distress when reminded of the trauma

2. **Avoidance**
   * Avoiding thoughts, feelings, or reminders of the event
   * Emotional numbness, detachment from others

3. **Negative cognitions & mood**
   * Distorted blame of self or others
   * Persistent negative emotions (fear, horror, anger, guilt, shame)
   * Loss of interest in activities
   * Feeling alienated from others

4. **Hyperarousal**
   * Easily startled, always on edge
   * Sleep problems, angry outbursts
   * Difficulty concentrating

**Evidence-Based Treatments**
* **EMDR (Eye Movement Desensitisation and Reprocessing)** — highly effective
* **Trauma-focused CBT** — structured exposure and cognitive processing
* **Prolonged Exposure Therapy**
* **Medication** — SSRIs (e.g., sertraline) are first-line

**Grounding for Flashbacks**
When a flashback hits, use sensory anchors to return to the present — hold something cold, state today's date aloud, name 5 things you can see around you."
    ],

    // ──────────────────────────────────────────────
    // STRESS & BURNOUT
    // ──────────────────────────────────────────────
    'stress' => [
        'keywords' => ['stress', 'stressed', 'overwhelmed', 'burnout', 'burnt out', 'exhausted', 'overloaded', 'pressure', 'academic pressure', 'exam stress', 'deadlines', 'workload', 'too much'],
        'answer' => "### Stress & Burnout

Stress is a response to external pressure. Burnout is what happens when that stress accumulates without adequate recovery — it is a state of chronic physical and emotional exhaustion.

**Signs of Burnout**
* Complete exhaustion — physical, mental, and emotional
* Detachment or cynicism towards studies, work, or people
* Reduced effectiveness and productivity
* Feeling ineffective and like nothing you do makes a difference
* Brain fog, difficulty concentrating
* Physical symptoms: frequent illness, headaches, muscle tension

**Stress Management Strategies**
* **Time blocking:** Schedule your work, breaks, meals, and sleep like appointments.
* **The 80/20 Rule:** 80% of results come from 20% of your efforts — identify your high-impact tasks.
* **Say no:** Protect your time and energy. Overcommitting is a fast path to burnout.
* **Micro-recovery:** 5-minute breaks every hour restore focus and prevent depletion.
* **Physical movement:** One of the most powerful stress buffers available. Even walking helps.
* **Progressive Muscle Relaxation (PMR):** Tense each muscle group for 5 seconds, then release. Work from feet to head.
* **Journaling:** Externalise racing thoughts onto paper to gain perspective.

**Burnout Recovery**
Recovery from burnout takes time. Prioritise sleep, reduce demands temporarily, reconnect with activities that restore you, and seek professional guidance if it persists."
    ],

    'academic_stress' => [
        'keywords' => ['exam', 'exams', 'study', 'studying', 'assignments', 'grades', 'failing', 'fail', 'college', 'university', 'coursework', 'dissertation', 'test anxiety', 'performance'],
        'answer' => "### Managing Academic Stress

University demands — exams, deadlines, assignments, and grades — can feel crushing. Here are strategies that work:

**Study Strategies That Reduce Anxiety**
* **Pomodoro Technique:** 25 minutes of focused study, then 5-minute break. After 4 rounds, take a 20-minute break.
* **Active Recall:** Test yourself rather than re-reading. Flashcards, past papers, and practice questions are gold.
* **Spaced Repetition:** Review material at increasing intervals instead of cramming.
* **Start early:** Break big tasks into 15-minute steps. Starting is the hardest part.

**The Night Before an Exam**
* Stop studying by 9pm — your brain consolidates memory during sleep.
* Prepare everything the night before (clothes, pencils, ID).
* Aim for 7–9 hours of sleep — sleep deprivation reduces cognitive performance dramatically.

**During the Exam**
* Read all questions before starting.
* Take 3 slow breaths if panic hits.
* Skip difficult questions and return — don't let one question derail the whole paper.

**Getting Support**
* Speak to your lecturer or tutor early if you are struggling — they want to help.
* Ask about extensions, accommodations, or academic support services if mental health is impacting your studies."
    ],

    // ──────────────────────────────────────────────
    // SELF-ESTEEM & SELF-WORTH
    // ──────────────────────────────────────────────
    'self_esteem' => [
        'keywords' => ['self-esteem', 'self esteem', 'self worth', 'self-worth', 'confidence', 'insecure', 'insecurity', 'worthless', 'not good enough', 'hate myself', 'body image', 'ugly', 'stupid', 'failure', 'compare', 'comparison', 'impostor', 'imposter syndrome'],
        'answer' => "### Self-Esteem & Self-Worth

Self-esteem is how we value and perceive ourselves. It is built over time through experiences, relationships, and internal narratives — and it can be rebuilt when it has been damaged.

**Signs of Low Self-Esteem**
* Harsh self-criticism — you speak to yourself in ways you'd never speak to a friend
* Fear of failure and avoiding challenges
* Seeking constant reassurance from others
* Social comparison, especially on social media
* Difficulty accepting compliments
* People-pleasing and difficulty saying no

**Building Self-Esteem — Evidence-Based Approaches**

1. **Challenge the inner critic:** When a negative thought appears (e.g., \"I'm stupid\"), ask: *\"Would I say this to my best friend? What would I say to them instead?\"*

2. **Self-compassion (Dr. Kristin Neff's framework):**
   * Acknowledge your suffering (mindfulness)
   * Recognise this is a shared human experience (common humanity)
   * Speak kindly to yourself (self-kindness)

3. **Behavioural experiments:** Test your feared beliefs. If you think \"people will laugh at me if I answer wrong in class\", try it and observe the actual outcome.

4. **Daily wins log:** Write 3 things you did well today — no matter how small. Your brain is wired to notice negatives; this practice actively counteracts that.

5. **Social media detox:** Comparison is the thief of joy. Curate your feed or take regular breaks.

**Imposter Syndrome**
Feeling like a fraud despite evidence of your competence is extremely common among students and high achievers. Recognising it by name is the first step. Keep a 'wins folder' of achievements and positive feedback to revisit when the imposter voice gets loud."
    ],

    // ──────────────────────────────────────────────
    // OCD
    // ──────────────────────────────────────────────
    'ocd' => [
        'keywords' => ['ocd', 'obsessive', 'compulsive', 'obsessions', 'compulsions', 'intrusive thoughts', 'rituals', 'checking', 'contamination', 'symmetry', 'counting', 'repeating'],
        'answer' => "### Obsessive-Compulsive Disorder (OCD)

OCD involves two key components: **obsessions** (unwanted, distressing intrusive thoughts) and **compulsions** (repetitive behaviours or mental acts performed to neutralise the anxiety caused by obsessions).

**Common Obsession Themes**
* Fear of contamination (germs, dirt, illness)
* Harm obsessions (fear of accidentally hurting self or others)
* Symmetry and order (things must be \"just right\")
* Forbidden thoughts (sexual, religious, or violent intrusive thoughts)
* Doubt (\"did I lock the door? Turn off the oven?\")

**Common Compulsions**
* Excessive cleaning, hand washing
* Checking locks, appliances, switches repeatedly
* Ordering, arranging until it feels \"right\"
* Mental rituals (praying, counting, reviewing)
* Reassurance seeking

**Important:** Intrusive thoughts in OCD are ego-dystonic — they go against your values and feel horrifying. They do not reflect your character or desires.

**Treatment**
* **Exposure and Response Prevention (ERP)** — the gold standard therapy. Gradually face feared situations without performing compulsions, allowing anxiety to naturally reduce.
* **SSRIs** (at higher doses than depression) are effective in conjunction with ERP.
* OCD is highly treatable. Most people see significant improvement with the right care."
    ],

    // ──────────────────────────────────────────────
    // ADHD
    // ──────────────────────────────────────────────
    'adhd' => [
        'keywords' => ['adhd', 'add', 'attention', 'concentration', 'focus', 'hyperactive', 'hyperactivity', 'impulsive', 'impulsivity', 'distracted', 'procrastination', 'procrastinate', 'forgetful', 'disorganised', 'disorganized'],
        'answer' => "### ADHD — Attention Deficit Hyperactivity Disorder

ADHD is a neurodevelopmental condition affecting attention regulation, impulse control, and executive functioning. It is often misunderstood — many people with ADHD can hyperfocus on topics they find engaging.

**Symptoms**
* **Inattentive type:** Difficulty sustaining attention, easily distracted, forgetful, loses things, doesn't follow through on tasks
* **Hyperactive-Impulsive type:** Fidgeting, leaving seat when expected to sit, talking excessively, blurting out answers, difficulty waiting
* **Combined type:** Both patterns present

**Common Challenges in University**
* Starting tasks, especially boring ones (task initiation deficit)
* Managing long-term projects without external structure
* Time blindness — poor perception of how long things take
* Emotional dysregulation — stronger emotional reactions

**Practical Strategies**
* **Body doubling:** Work alongside someone else (even over video call) — presence increases focus.
* **External structure:** Timers, alarms, planners, accountability partners
* **Break tasks into micro-steps:** \"Write essay\" → \"Open document\" → \"Write one sentence\"
* **Interest bridges:** Link boring tasks to something engaging (music, rewards)
* **Remove distractions:** Phone in another room, website blockers, noise-cancelling headphones

**Treatment Options**
* Stimulant medications (methylphenidate, amphetamines) are highly effective
* Non-stimulant alternatives are available
* CBT adapted for ADHD
* Academic accommodations (extended time, quiet exam rooms) — speak to disability services"
    ],

    // ──────────────────────────────────────────────
    // EATING DISORDERS
    // ──────────────────────────────────────────────
    'eating_disorders' => [
        'keywords' => ['eating disorder', 'anorexia', 'bulimia', 'binge eating', 'binging', 'purging', 'restriction', 'not eating', 'starving', 'starvation', 'food fear', 'body dysmorphia', 'food', 'calories', 'weight', 'diet', 'overeat'],
        'answer' => "### Eating Disorders

Eating disorders are serious mental health conditions with significant physical health consequences. They are not a lifestyle choice or about food itself — they are complex psychological conditions often involving control, self-worth, trauma, and emotion regulation.

**Common Types**

* **Anorexia Nervosa** — Severe restriction of food intake, intense fear of weight gain, distorted body image. Medically the most dangerous psychiatric condition.
* **Bulimia Nervosa** — Cycles of binge eating followed by compensatory behaviours (vomiting, laxatives, excessive exercise).
* **Binge Eating Disorder (BED)** — Recurrent episodes of eating large quantities with distress, without compensatory behaviours. The most common eating disorder.
* **ARFID (Avoidant/Restrictive Food Intake Disorder)** — Extreme food selectivity not driven by body image concerns (often in neurodivergent individuals).

**Warning Signs**
* Preoccupation with food, weight, calories, dieting
* Dramatic weight changes
* Avoiding meals, excuses not to eat
* Going to the bathroom immediately after meals
* Physical signs: hair loss, fainting, dental erosion, bloating, lanugo (fine body hair)
* Excessive or compulsive exercise despite illness or injury

**Getting Help**
Eating disorders require specialised treatment teams (psychiatrists, dietitians, therapists). The earlier treatment begins, the better the prognosis. Please speak to a counselor or doctor — do not manage this alone."
    ],

    // ──────────────────────────────────────────────
    // BORDERLINE PERSONALITY DISORDER
    // ──────────────────────────────────────────────
    'bpd' => [
        'keywords' => ['bpd', 'borderline', 'personality disorder', 'emotional dysregulation', 'unstable relationships', 'fear of abandonment', 'abandonment', 'splitting', 'identity', 'impulsive behaviour', 'self-destructive'],
        'answer' => "### Borderline Personality Disorder (BPD)

BPD is characterised by intense emotional experiences, unstable relationships, an unstable sense of self, and impulsive behaviours. It develops in response to a combination of genetic factors and invalidating early environments.

**Core Features**
* Intense fear of real or imagined abandonment
* Unstable, intense relationships that alternate between idealisation and devaluation (\"splitting\")
* Unstable or fragmented sense of identity
* Impulsive, self-damaging behaviour (spending, sex, substance use, reckless driving)
* Recurrent self-harm or suicidal threats/behaviours
* Severe emotional mood swings (lasting hours to days)
* Chronic feelings of emptiness
* Intense, inappropriate anger
* Stress-related paranoid thoughts or dissociation

**Treatment**
* **Dialectical Behaviour Therapy (DBT)** — developed specifically for BPD. Teaches mindfulness, distress tolerance, emotion regulation, and interpersonal effectiveness skills.
* **Mentalisation-Based Therapy (MBT)** — focuses on understanding your own and others' mental states.
* Medication is not curative but can help with specific symptoms (mood, impulsivity).

**Note:** BPD is highly treatable. Many people achieve full remission with appropriate therapy. A diagnosis is not a life sentence."
    ],

    // ──────────────────────────────────────────────
    // SCHIZOPHRENIA & PSYCHOSIS
    // ──────────────────────────────────────────────
    'psychosis' => [
        'keywords' => ['schizophrenia', 'psychosis', 'psychotic', 'hallucinations', 'hearing voices', 'voices', 'delusions', 'paranoia', 'paranoid', 'reality', 'losing touch with reality', 'delusional'],
        'answer' => "### Psychosis & Schizophrenia

Psychosis describes a temporary or persistent disruption in a person's connection to reality. It can occur in schizophrenia, bipolar disorder, severe depression, substance use, or as a standalone episode.

**Symptoms of Psychosis**
* **Hallucinations** — perceiving things that aren't there (hearing voices is most common, but can also be visual, tactile, or olfactory)
* **Delusions** — fixed, false beliefs (e.g., believing you are being followed, that you have special powers, or that your thoughts are being broadcast)
* **Disorganised thinking** — speech that is incoherent, jumping between unrelated topics
* **Negative symptoms** — flat affect, social withdrawal, poverty of speech, avolition (lack of motivation)

**If You Or Someone Else is Experiencing Psychosis**
This is a medical emergency if the person is in danger. Seek immediate help from a mental health crisis team or emergency services.

**Treatment**
* Antipsychotic medications are the primary treatment
* CBT for psychosis (CBTp) helps people challenge distressing beliefs and manage symptoms
* Family therapy and psychoeducation are critical for long-term recovery
* Early intervention dramatically improves outcomes — do not wait."
    ],

    // ──────────────────────────────────────────────
    // SLEEP
    // ──────────────────────────────────────────────
    'sleep' => [
        'keywords' => ['sleep', 'insomnia', 'can\'t sleep', 'cant sleep', 'sleeping too much', 'hypersomnia', 'nightmares', 'night sweats', 'sleep problems', 'tiredness', 'fatigue', 'rest', 'bedtime', 'waking up'],
        'answer' => "### Sleep & Mental Health

Sleep and mental health have a bidirectional relationship — poor sleep worsens mental health, and mental health problems disrupt sleep. Getting this right is one of the highest-yield changes you can make.

**Sleep Hygiene — Evidence-Based Rules**
* **Consistent sleep/wake times** — even on weekends. Regularity is more important than duration.
* **No screens 60 minutes before bed** — blue light suppresses melatonin production.
* **Cool, dark, quiet room** — 16–19°C (61–67°F) is optimal for sleep.
* **Bed is for sleep only** — do not study, eat, or scroll in bed. Your brain must associate it with sleep.
* **No caffeine after 2pm** — caffeine has a 6-hour half-life.
* **Avoid alcohol as a sleep aid** — it fragments sleep architecture and suppresses REM.
* **Exercise regularly** — but not within 2 hours of bedtime.

**If You Cannot Sleep (Stimulus Control Therapy)**
* If you've been awake for 20 minutes, get up and do something calm in dim light. Return to bed only when sleepy.
* Do not clock-watch.

**Relaxation Techniques for Sleep**
* **4-7-8 Breathing:** Inhale 4 counts, hold 7, exhale 8.
* **Progressive Muscle Relaxation:** Tense and release muscle groups from feet to head.
* **Body scan meditation:** Bring attention slowly from toes to head, relaxing each area.

**When to Seek Help**
If insomnia has lasted more than 3 months and is impacting your functioning, Cognitive Behavioural Therapy for Insomnia (CBT-I) is the most effective long-term treatment."
    ],

    // ──────────────────────────────────────────────
    // GRIEF & LOSS
    // ──────────────────────────────────────────────
    'grief' => [
        'keywords' => ['grief', 'grieving', 'loss', 'bereavement', 'death', 'died', 'dead', 'mourning', 'missing someone', 'loved one', 'funeral', 'passing'],
        'answer' => "### Grief & Loss

Grief is the natural response to loss — not only the death of a loved one, but also the loss of a relationship, a future you expected, your health, or your sense of safety.

**The Stages of Grief (Kübler-Ross)**
These are not linear stages — people move back and forth between them:
* *Denial* — Numbness, disbelief, shock
* *Anger* — Frustration, \"why me?\", displaced rage
* *Bargaining* — \"If only I had…\", \"What if…\"
* *Depression* — Deep sadness, withdrawal, longing
* *Acceptance* — Learning to live with the loss (not \"getting over\" it)

**What Normal Grief Looks Like**
* Waves of intense sadness, often triggered unexpectedly
* Difficulty concentrating or making decisions
* Physical symptoms: fatigue, appetite changes, chest tightness
* Social withdrawal, tearfulness

**Complicated Grief (Prolonged Grief Disorder)**
When grief remains intensely debilitating for over 12 months, interfering with functioning, this may be Prolonged Grief Disorder, which responds well to specialised therapy.

**Supportive Practices**
* Allow yourself to feel — grief must be processed, not suppressed
* Maintain daily routines where possible
* Accept support from others
* Create rituals to honour the person you have lost
* Be patient — there is no timeline for grief
* Speak to a counselor if grief feels unmanageable"
    ],

    // ──────────────────────────────────────────────
    // LONELINESS & SOCIAL ISOLATION
    // ──────────────────────────────────────────────
    'loneliness' => [
        'keywords' => ['lonely', 'loneliness', 'alone', 'isolated', 'isolation', 'no friends', 'friendless', 'left out', 'excluded', 'social withdrawal', 'no one cares', 'nobody'],
        'answer' => "### Loneliness & Social Isolation

Loneliness is a painful subjective feeling of disconnection — you can be surrounded by people and still feel profoundly alone. Chronic loneliness has similar health effects to smoking 15 cigarettes a day.

**Why University Can Be Especially Lonely**
* Being away from home and established social networks
* Comparison with peers who seem to be thriving socially
* Social media projecting everyone else's highlight reel
* New environments without shared history

**Building Connection — Practical Steps**
* **Start small:** A genuine smile, asking a classmate how their weekend was. Small interactions build bridges.
* **Pursue interest-based activities:** Clubs, societies, volunteering. Shared activities create connection far more naturally than forced socialisation.
* **Quality over quantity:** One authentic friendship is worth more than 20 surface-level ones.
* **Online communities:** Forums, Discord servers, and communities around shared interests can be meaningful connection.
* **Be consistent:** Relationships are built through repeated, reliable contact over time.

**For the Inner Experience**
* Challenge the thought \"No one wants to spend time with me\" — is there actual evidence for this?
* Practice self-compassion — loneliness is painful; you don't also need to judge yourself for experiencing it.
* Speak to a counselor — loneliness is one of the most common reasons students seek support."
    ],

    // ──────────────────────────────────────────────
    // ANGER MANAGEMENT
    // ──────────────────────────────────────────────
    'anger' => [
        'keywords' => ['anger', 'angry', 'rage', 'furious', 'temper', 'irritable', 'irritability', 'aggression', 'aggressive', 'explosive', 'losing control', 'outburst'],
        'answer' => "### Anger & Anger Management

Anger is a normal, healthy emotion. It becomes a problem when it is too frequent, too intense, lasts too long, or leads to behaviour that harms yourself or others.

**The Anger Cycle**
Trigger → Thoughts (appraisal) → Physical arousal → Behaviour

**Physical Warning Signs of Escalating Anger**
* Increased heart rate and breathing
* Muscle tension, clenched jaw or fists
* Feeling hot, flushed face
* Tunnel vision, feeling trapped

**De-escalation Strategies — In the Moment**
* **STOP:** Physically stop what you are doing.
* **Take space:** Remove yourself from the trigger. \"I need 20 minutes, then I'll come back to this.\"
* **Breathe:** Long exhales activate the parasympathetic nervous system.
* **Cool down physically:** Cold water on wrists or face.
* **Identify the feeling beneath:** Anger often protects sadness, fear, shame, or hurt.

**Long-Term Anger Management**
* Identify your triggers and early warning signs
* Challenge angry thoughts: Is the situation as threatening as you initially perceived?
* Improve communication: Use \"I feel...\" statements rather than blaming language
* Regular physical exercise releases built-up physiological tension
* Mindfulness meditation reduces baseline reactivity over time

**When to Seek Help**
If anger is damaging your relationships, resulting in physical aggression, or you feel out of control, a therapist trained in anger management can help significantly."
    ],

    // ──────────────────────────────────────────────
    // SELF-HARM
    // ──────────────────────────────────────────────
    'self_harm' => [
        'keywords' => ['self harm', 'self-harm', 'cutting', 'hurting myself', 'hurt myself', 'self injury', 'self-injury', 'burning myself', 'scratching', 'harming myself'],
        'answer' => "### Self-Harm — Understanding & Support

Self-harm (non-suicidal self-injury, NSSI) is deliberately injuring yourself to cope with overwhelming emotional pain. It is **not** the same as a suicide attempt, though it requires urgent support.

**Why People Self-Harm**
* To feel physical pain instead of emotional pain (a form of dissociation relief)
* To feel something when emotionally numb
* To punish themselves (linked to shame, guilt, or self-criticism)
* To communicate distress they cannot articulate
* To regain a sense of control

**This Is Important to Know**
* Self-harm provides temporary relief but does not address the underlying pain.
* It can escalate over time.
* It is often kept secret due to shame — but shame is not your fault.
* Recovery is absolutely possible with the right support.

**Alternatives to Self-Harm (Distress Tolerance)**
These are *not* dismissive — they are genuine, evidence-based alternatives used in DBT:
* Hold ice cubes — the intense cold sensation provides a similar sensory experience
* Snap a rubber band on your wrist
* Intense exercise — run, jump, do press-ups
* Scream into a pillow
* Write out your feelings furiously, then tear up the paper
* Call a trusted friend or crisis line

**Please Reach Out**
Self-harm is a signal that you need more support than you currently have. Please speak to a counselor, doctor, or crisis line. You do not need to manage this alone. Use the Book Counselor tab to arrange an urgent session."
    ],

    // ──────────────────────────────────────────────
    // SUICIDAL THOUGHTS
    // ──────────────────────────────────────────────
    'suicidal' => [
        'keywords' => ['suicide', 'suicidal', 'kill myself', 'end my life', 'want to die', 'rather be dead', 'not worth living', 'no reason to live', 'better off dead', 'ending it all', 'give up on life', 'life not worth it'],
        'answer' => "### 🚨 You Are Not Alone — Immediate Support Available

If you are having thoughts of suicide or self-harm, please know that what you are experiencing is a crisis — and crises can be survived. Many people who have felt exactly what you feel right now have gone on to live fulfilling lives.

**Please Do One of These Right Now:**
* **Book an urgent counselor session** on the Book Counselor tab — select urgent
* **Call emergency services** (local emergency number) if you are in immediate danger
* **Crisis Text Line:** Text HOME to 741741 (available in many countries)
* **International Association for Suicide Prevention:** https://www.iasp.info/resources/Crisis_Centres/
* **Befrienders International:** www.befrienders.org
* **Samaritans (UK/Ireland):** 116 123
* **988 Suicide & Crisis Lifeline (USA):** Call or text 988

**If You Feel Able, Try This Right Now:**
1. Move to a safe physical space
2. Call or text someone — a friend, family member, or crisis line
3. Stay with that person or stay on the line until the feeling passes

**Understanding Suicidal Thoughts**
Suicidal thoughts are symptoms of pain that has exceeded your current capacity to cope — they are not a rational assessment of your worth or your future. The part of your mind that is considering this is in extreme distress; it does not see the full picture.

**You deserve support and care. Please reach out.**"
    ],

    // ──────────────────────────────────────────────
    // COPING TECHNIQUES
    // ──────────────────────────────────────────────
    'grounding' => [
        'keywords' => ['grounding', 'ground', '54321', '5-4-3-2-1', 'present moment', 'in the moment', 'dissociation', 'dissociate', 'dissociating', 'unreal', 'unreality', 'derealization', 'depersonalisation'],
        'answer' => "### Grounding Techniques — Return to the Present

Grounding anchors you to the present moment when anxiety, panic, flashbacks, or dissociation pulls you away from it. These are your emergency tools.

**The 5-4-3-2-1 Method**
Look around you right now and find:
* 👁️ **5 things you can SEE** (a clock, a plant, a colour on the wall)
* 🖐️ **4 things you can TOUCH** (the texture of your clothes, the table, a cold glass)
* 👂 **3 things you can HEAR** (traffic, your own breathing, a fan humming)
* 👃 **2 things you can SMELL** (coffee, soap, your own hair)
* 👅 **1 thing you can TASTE** (a mint, water, or just the air)
Take a deep, slow breath after completing this.

**More Grounding Techniques**

*Physical:*
* Place your feet firmly flat on the floor. Feel the ground.
* Hold something cold (ice, a cold drink) — the sensation breaks the dissociation.
* Splash cold water on your face or run your wrists under cold water.
* Name and move each body part slowly.

*Mental:*
* Name today's date, time, and where you are.
* Count backwards from 100 in 7s.
* Recite a poem, song lyrics, or alphabet backwards.
* List all the countries/capitals you can remember.

*Soothing:*
* Wrapped in a blanket, hum a calming song.
* Hold a comforting object with strong associations (a familiar mug, a piece of jewellery).
* Look at a photo that makes you feel safe."
    ],

    'breathing' => [
        'keywords' => ['breathing', 'breathe', 'breath', 'breathing exercise', 'diaphragm', 'deep breathing', 'box breathing', 'calm down', 'relax'],
        'answer' => "### Breathing Exercises for Anxiety & Stress

Breathing is the only autonomic nervous system function you can voluntarily control — making it a direct on/off switch for your stress response.

**1. Box Breathing (Tactical Breathing)**
Used by military and emergency services for high-pressure situations:
* Inhale through nose → 4 counts
* Hold → 4 counts
* Exhale through mouth → 4 counts
* Pause empty → 4 counts
Repeat 4–6 cycles.

**2. 4-7-8 Breathing (Relaxation Response)**
Developed by Dr. Andrew Weil:
* Inhale through nose → 4 counts
* Hold breath → 7 counts
* Exhale fully through mouth → 8 counts
The extended exhale is key — it activates the parasympathetic nervous system strongly.

**3. Diaphragmatic (Belly) Breathing**
Most people breathe shallowly into their chest during stress. Belly breathing reverses this:
* Place one hand on your chest, one on your belly.
* Breathe in slowly — only the belly hand should rise.
* Exhale slowly — belly falls.
* 6–8 breaths per minute is the target.

**4. Physiological Sigh**
The fastest way to reduce stress in real-time (Dr. Andrew Huberman):
* Take a normal inhale through the nose.
* Before exhaling, take a second short inhale to fully inflate the lungs.
* Exhale completely and slowly through the mouth.
Do this 1–3 times — it deflates the alveoli in the lungs and signals safety to the brain."
    ],

    'mindfulness' => [
        'keywords' => ['mindfulness', 'meditation', 'meditate', 'present', 'awareness', 'zen', 'calm', 'stillness', 'breathing space', 'body scan', 'mindful'],
        'answer' => "### Mindfulness & Meditation

Mindfulness is the practice of bringing deliberate, non-judgmental attention to the present moment. It physically changes the brain over time — reducing the amygdala (fear centre) and strengthening the prefrontal cortex (reasoning).

**Mindfulness ≠ Emptying Your Mind**
The goal is to observe thoughts without getting caught in them — like watching clouds pass across a sky, rather than being the cloud.

**Starter Practice: 3-Minute Breathing Space**
1. **Minute 1 — Awareness:** Ask \"What am I experiencing right now?\" Notice thoughts, feelings, bodily sensations without judging them.
2. **Minute 2 — Focus:** Narrow your attention entirely to your breath. Each time your mind wanders, gently return.
3. **Minute 3 — Expand:** Widen awareness to your whole body, then the room around you.

**Body Scan Meditation (15 minutes)**
Lie down. Bring slow attention to each body part from your toes upward — noticing sensations, tension, or numbness without trying to change them. This is deeply calming for the nervous system.

**Benefits of Regular Practice**
* Reduced anxiety and rumination
* Improved emotional regulation
* Better sleep quality
* Reduced physical pain sensitivity
* Increased self-awareness

**Apps to Start:** Headspace, Calm, Insight Timer (free), Smiling Mind"
    ],

    'cbt' => [
        'keywords' => ['cbt', 'cognitive behavioral', 'cognitive behaviour', 'therapy', 'thought patterns', 'negative thoughts', 'reframing', 'thoughts feelings behaviours', 'automatic thoughts', 'cognitive distortions'],
        'answer' => "### Cognitive Behavioural Therapy (CBT)

CBT is the most extensively researched psychological treatment in existence. Its core insight: **your thoughts shape your feelings, which influence your behaviour** — and all three can be changed.

**The CBT Triangle**
```
     THOUGHTS
      /    \\
EMOTIONS ↔ BEHAVIOURS
```
Changing any one element creates change in the others.

**Common Cognitive Distortions (Thinking Errors)**
* **All-or-nothing thinking:** \"I failed that test — I'm a total failure\"
* **Catastrophising:** Assuming the worst will always happen
* **Mind reading:** \"They didn't smile — they must hate me\"
* **Fortune telling:** Predicting negative outcomes as facts
* **Emotional reasoning:** \"I feel stupid, therefore I must be stupid\"
* **Personalisation:** Blaming yourself for things outside your control
* **Should statements:** Rigid rules that generate guilt and shame
* **Mental filter:** Noticing only the negatives, ignoring positives
* **Overgeneralisation:** \"This always happens to me\"

**How to Challenge a Thought**
1. Write down the automatic thought: *\"I'm going to fail this exam\"*
2. Rate your belief in it (0–100%): *\"85%\"*
3. Ask: What is the evidence FOR this thought?
4. Ask: What is the evidence AGAINST it?
5. Generate a more balanced alternative thought
6. Re-rate your belief: *\"40%\"*

**Behavioural Experiments**
Test your beliefs in real life rather than just arguing with them in your head. They almost always reveal that your feared outcome was less likely than you thought."
    ],

    'dbt' => [
        'keywords' => ['dbt', 'dialectical', 'dialectical behaviour therapy', 'distress tolerance', 'emotion regulation', 'interpersonal effectiveness', 'radical acceptance', 'tipp'],
        'answer' => "### Dialectical Behaviour Therapy (DBT)

Developed by Dr. Marsha Linehan (who has BPD herself), DBT teaches skills across four modules to help people with intense emotional experiences.

**The 4 Skill Modules**

**1. Mindfulness** — The foundation of all DBT skills
* Observe: Notice experiences without judgment
* Describe: Put words to experiences
* Participate: Engage fully in the current moment

**2. Distress Tolerance** — Surviving crises without making things worse
* **TIPP:** Temperature (cold water), Intense exercise, Paced breathing, Progressive relaxation
* **ACCEPTS:** Activities, Contributing, Comparisons, Emotions (opposite), Pushing away, Thoughts, Sensations
* **Radical Acceptance:** Accepting reality as it is (not approval — just acknowledgment)

**3. Emotion Regulation** — Understanding and changing emotions
* Identify and name the emotion (naming reduces intensity)
* **PLEASE:** PhysicaL illness care, balanced Eating, Avoiding substances, Sleep, Exercise
* **Opposite Action:** When the emotion's action urge won't help, do the opposite
* Build positive experiences (schedule enjoyable activities)

**4. Interpersonal Effectiveness** — Healthy relationships and self-respect
* **DEAR MAN:** Describe, Express, Assert, Reinforce, Mindful, Appear confident, Negotiate — for making requests
* **GIVE:** Gentle, Interested, Validate, Easy manner — for maintaining relationships
* **FAST:** Fair, Apologies (no excessive ones), Stick to values, Truthful — for self-respect"
    ],

    // ──────────────────────────────────────────────
    // SUBSTANCE USE
    // ──────────────────────────────────────────────
    'substance_use' => [
        'keywords' => ['alcohol', 'drinking', 'drunk', 'addiction', 'addicted', 'substance', 'drugs', 'weed', 'cannabis', 'marijuana', 'smoking', 'vaping', 'cocaine', 'dependent', 'dependence', 'withdrawal', 'sober', 'sobriety'],
        'answer' => "### Substance Use & Mental Health

Substance use and mental health are deeply intertwined. Many people use substances to self-medicate mental health symptoms — but over time, substances worsen the very symptoms they temporarily relieve.

**How Substances Affect Mental Health**
* **Alcohol:** Depressant. Short-term anxiety relief followed by rebound anxiety and worsened depression.
* **Cannabis:** Can trigger or worsen anxiety, paranoia, and psychosis — especially high-THC strains.
* **Stimulants (cocaine, amphetamines):** Can precipitate panic attacks, paranoia, psychosis.
* **All substances:** Disrupt sleep quality, deplete neurotransmitters, reduce motivation.

**Signs of Problem Use**
* Using more than intended or for longer than intended
* Failed attempts to cut down or stop
* Spending significant time obtaining, using, or recovering
* Craving or strong urge to use
* Use continues despite negative consequences (relationships, academic, health)
* Tolerance has developed (needing more to get the same effect)
* Withdrawal symptoms when stopping

**Harm Reduction Principles**
* If you are going to use, reduce risk (stay with trusted people, avoid mixing substances)
* Track your use — many people are surprised by the reality vs. their perception
* Know your triggers (stress, social situations, emotions) — address those directly

**Getting Support**
Speak to a counselor confidentially. There is no judgment here. Addressing the underlying mental health issues alongside substance use is the most effective approach."
    ],

    // ──────────────────────────────────────────────
    // RELATIONSHIPS
    // ──────────────────────────────────────────────
    'relationships' => [
        'keywords' => ['relationship', 'relationships', 'breakup', 'break up', 'heartbreak', 'partner', 'boyfriend', 'girlfriend', 'love', 'toxic relationship', 'abuse', 'domestic violence', 'controlling', 'manipulation', 'betrayal', 'trust'],
        'answer' => "### Relationships & Mental Health

Our relationships are one of the greatest predictors of mental health — both positively and negatively.

**Signs of a Healthy Relationship**
* Mutual respect and trust
* Open and honest communication
* Individual autonomy — both people maintain their own friendships, interests, identity
* Conflict is resolved through discussion, not control or punishment
* Both people feel safe to be themselves

**Signs of a Toxic or Abusive Relationship**
* Controlling behaviour — monitoring your phone, movements, friendships
* Jealousy framed as love
* Emotional manipulation, gaslighting (making you question your own reality)
* Isolation from friends and family
* Threats, intimidation, or humiliation
* Physical, sexual, or financial abuse
* Walking on eggshells — constant fear of their reaction

**After a Breakup**
* Allow yourself to grieve — a relationship ending is a real loss
* Maintain a routine and social connections
* Do not make major decisions in the acute phase
* Avoid contact if it prolongs pain
* Resist the urge to check their social media

**If You Are in an Abusive Situation**
You do not have to handle this alone. Please speak to a counselor confidentially, or contact a domestic violence helpline in your country. Your safety comes first."
    ],

    // ──────────────────────────────────────────────
    // IDENTITY & GENDER
    // ──────────────────────────────────────────────
    'identity' => [
        'keywords' => ['identity', 'who am i', 'sense of self', 'lgbtq', 'gay', 'lesbian', 'bisexual', 'transgender', 'non-binary', 'gender', 'sexuality', 'coming out', 'queer', 'questioning'],
        'answer' => "### Identity, Gender & Sexuality

Exploring who you are — your values, sexuality, gender identity, cultural identity, and sense of self — is one of the central psychological tasks of young adulthood. This process can be rich but also deeply challenging.

**Psychological Wellbeing & LGBTQ+ Identity**
Research consistently shows that LGBTQ+ individuals experience higher rates of depression, anxiety, and suicidality — not because of their identity itself, but due to minority stress: stigma, discrimination, family rejection, and internalised shame.

**The Coming Out Process**
There is no right timeline. Coming out is deeply personal, and safety (physical and emotional) must come first. You do not owe anyone your identity before you are ready.

**Building a Healthy Sense of Self**
* **Values clarification:** What matters most to you? Your values are the bedrock of identity.
* **Separating your worth from external validation:** Your value as a person is not determined by grades, social status, or others' approval.
* **Exploring identity safely:** LGBTQ+ student groups, online communities, and affirming counselors provide spaces to explore.

**Affirming Support**
If you are struggling with identity, sexuality, or gender, please reach out to a counselor who works from an affirming (not conversion-based) model. You deserve support that accepts all of who you are."
    ],

    // ──────────────────────────────────────────────
    // PHYSICAL HEALTH CONNECTION
    // ──────────────────────────────────────────────
    'physical_health' => [
        'keywords' => ['exercise', 'physical', 'fitness', 'sport', 'nutrition', 'diet', 'gut health', 'inflammation', 'brain', 'body', 'movement', 'yoga', 'walk', 'run'],
        'answer' => "### The Mind-Body Connection

Your mental health is inseparably linked to your physical health. The body and brain are not separate systems.

**Exercise & Mental Health**
* Exercise is as effective as antidepressants for mild-to-moderate depression (Blumenthal et al.)
* Increases BDNF (Brain-Derived Neurotrophic Factor) — literally grows new brain cells
* Reduces cortisol (stress hormone)
* Improves sleep quality
* Boosts dopamine, serotonin, and endorphins
* Even 20 minutes of walking 3x per week shows measurable benefits

**Nutrition & Mental Health**
* The **gut-brain axis** — 90% of serotonin is produced in the gut.
* Diets high in ultra-processed foods are associated with higher rates of depression and anxiety.
* Key nutrients for brain health: Omega-3 fatty acids, B vitamins (especially B12 and folate), magnesium, Vitamin D, zinc.
* Blood sugar stability matters: erratic eating patterns worsen mood, energy, and concentration.
* Hydration: even mild dehydration impairs cognitive function and mood.

**Yoga & Movement**
Yoga combines physical movement with breath work and mindfulness — triple benefit for mental health. Even gentle stretching reduces cortisol and muscle tension.

**Practical Tip:** If you can only do one thing for your mental health, commit to moving your body for 20+ minutes each day. The evidence for this is stronger than for almost any other single lifestyle intervention."
    ],

    // ──────────────────────────────────────────────
    // COUNSELORS & THERAPY
    // ──────────────────────────────────────────────
    'therapy' => [
        'keywords' => ['therapy', 'therapist', 'counselor', 'counselling', 'counseling', 'psychiatrist', 'psychologist', 'psychotherapy', 'session', 'appointment', 'professional help', 'medication', 'antidepressants', 'treatment'],
        'answer' => "### Types of Therapy & Professional Support

Seeking professional support is a sign of strength and self-awareness. There are many different types of help available.

**Therapy Types**

* **Cognitive Behavioural Therapy (CBT)** — structured, evidence-based, focuses on thought-behaviour patterns. Best for: anxiety, depression, OCD, phobias.

* **Dialectical Behaviour Therapy (DBT)** — teaches distress tolerance, emotion regulation, mindfulness, and interpersonal skills. Best for: BPD, self-harm, emotional dysregulation.

* **EMDR** — Eye Movement Desensitisation and Reprocessing. Best for: PTSD and trauma.

* **Person-Centred Therapy** — the therapist provides unconditional positive regard; you lead the exploration. Best for: self-esteem, identity, life transitions.

* **Psychodynamic Therapy** — explores unconscious patterns and early experiences. Best for: complex relational patterns, identity.

* **ACT (Acceptance and Commitment Therapy)** — helps you accept difficult thoughts/feelings rather than fighting them, and commit to values-based action.

* **Schema Therapy** — addresses deep-seated patterns (schemas) that drive persistent problems.

**What Happens in a First Session?**
A first session is usually an assessment — the counselor asks about your current difficulties, history, and goals. You don't have to share everything at once. It is also an opportunity for you to see if you feel safe with that counselor.

**What If It's Not a Good Fit?**
The therapeutic relationship is the most important predictor of outcomes. It is absolutely acceptable — even encouraged — to find a different counselor if the fit isn't right.

**Medication**
* Prescribed by psychiatrists or GPs — not by counselors or psychologists
* SSRIs (e.g., sertraline, fluoxetine) are first-line for anxiety and depression
* Not a \"quick fix\" — they typically take 4–6 weeks to reach full effect
* Best outcomes when combined with therapy"
    ],

    // ──────────────────────────────────────────────
    // SUPPORTING OTHERS
    // ──────────────────────────────────────────────
    'helping_others' => [
        'keywords' => ['friend', 'helping', 'support someone', 'help a friend', 'worried about someone', 'someone i know', 'roommate', 'classmate', 'what to say', 'how to help', 'someone struggling'],
        'answer' => "### How to Support Someone Struggling With Their Mental Health

Watching someone you care about struggle is painful. Here's how to help effectively without burning yourself out.

**What Actually Helps**
* **Listen first** — Don't jump to advice or solutions. Ask \"Do you want me to just listen, or would you like suggestions?\"
* **Validate their experience** — \"That sounds incredibly hard\" or \"I can understand why you'd feel that way.\"
* **Be present consistently** — Check in regularly, not just in the acute crisis.
* **Ask directly about suicidal thoughts if concerned** — Asking does NOT plant the idea. Asking shows you care and opens the door: *\"I'm worried about you — are you having thoughts of suicide?\"*
* **Offer practical help** — Walk them to the counselor's office, sit with them while they make the call.

**What to Avoid**
* \"Just think positive\" or \"other people have it worse\"
* Trying to fix or minimise their feelings
* Breaking confidence unless there is immediate risk to life
* Making them feel like a burden for sharing

**Protecting Your Own Wellbeing**
* You cannot pour from an empty cup. Set limits on what you can offer.
* You are a friend, not a therapist — it is not your responsibility to fix them.
* Seek support yourself if supporting them is taking a toll.
* Know the emergency numbers and when to involve others.

**When to Escalate**
If your friend is in immediate danger of harming themselves or others, contact emergency services. Their life is more important than their anger at you for doing so."
    ],

    // ──────────────────────────────────────────────
    // CRISIS RESOURCES
    // ──────────────────────────────────────────────
    'crisis' => [
        'keywords' => ['crisis', 'emergency', 'help line', 'helpline', 'hotline', 'crisis line', 'urgent help', 'immediate help', 'in danger', 'not safe', '988', 'samaritans', 'lifeline', 'crisis resources'],
        'answer' => "### 🚨 Crisis Resources & Emergency Support

**If you are in immediate physical danger, call your local emergency services (e.g., 911, 999, 112) or go to your nearest Emergency Room.**

---

**International Crisis Lines**

| Country | Line |
|---|---|
| South Africa | SADAG: 0800 567 567 (24/7, free) |
| USA | 988 Suicide & Crisis Lifeline (call or text 988) |
| UK / Ireland | Samaritans: 116 123 |
| Australia | Lifeline: 13 11 14 |
| New Zealand | Lifeline: 0800 543 354 |
| India | iCall: 9152987821 |
| International | Crisis Text Line: Text HOME to 741741 |

**Online International Directory**
* Befrienders Worldwide: www.befrienders.org
* IASP Crisis Centres: www.iasp.info/resources/Crisis_Centres/

---

**On This Platform**
* Use the **Book Counselor** tab to request an urgent appointment
* Contact your university's student wellness centre directly

---

*You do not have to be suicidal to use a crisis line. If you are overwhelmed, in distress, or just need to talk to someone, these services exist for you.*"
    ],

    // ──────────────────────────────────────────────
    // RESILIENCE & RECOVERY
    // ──────────────────────────────────────────────
    'resilience' => [
        'keywords' => ['resilience', 'recovery', 'healing', 'bounce back', 'cope', 'coping', 'strength', 'overcome', 'moving forward', 'hope', 'better', 'improve', 'getting help'],
        'answer' => "### Resilience & Recovery

Resilience is not the absence of suffering — it is the ability to adapt and grow through adversity. It is a skill that can be built, not a fixed trait you either have or don't.

**Pillars of Psychological Resilience**

1. **Connection:** Strong social bonds are the single most powerful protective factor. Prioritise relationships.

2. **Meaning:** Finding purpose — even small meaning in daily activities — buffers against hopelessness. What matters to you? What would you like your life to stand for?

3. **Self-efficacy:** The belief that your actions can influence outcomes. Build this by setting small goals and achieving them consistently.

4. **Optimism (realistic):** This isn't about toxic positivity — it's about believing that things *can* get better, even if you don't know how yet.

5. **Flexibility:** The ability to adapt when plans change or difficulties arise. Rigid thinking increases vulnerability.

6. **Self-care foundations:** Sleep, movement, nutrition, and connection create the biological and social substrate for resilience.

**Post-Traumatic Growth**
Research shows that many people — after experiencing significant trauma or adversity — report meaningful positive changes in their lives: stronger relationships, new possibilities, greater personal strength, spiritual development, and a deeper appreciation for life.

**Recovery Is Not Linear**
There will be good days and hard days. Progress often looks like two steps forward, one step back. This is normal and expected — it does not mean recovery has failed."
    ],

];

// =============================================================
//  MATCHING ENGINE
// =============================================================
$matchedAnswer = null;
$lowercaseQuestion = strtolower($question);

// Priority: longer keyword phrases first to avoid partial conflicts
// Sort entries by max keyword length (descending) for better matching
uasort($knowledgeBase, function($a, $b) {
    $maxA = max(array_map('strlen', $a['keywords']));
    $maxB = max(array_map('strlen', $b['keywords']));
    return $maxB - $maxA;
});

foreach ($knowledgeBase as $topic => $data) {
    foreach ($data['keywords'] as $keyword) {
        if (strpos($lowercaseQuestion, strtolower($keyword)) !== false) {
            $matchedAnswer = $data['answer'];
            break 2;
        }
    }
}

// Default fallback — answer directly what was asked
if (!$matchedAnswer) {
    $cleanQ = htmlspecialchars($question, ENT_QUOTES, 'UTF-8');
    $matchedAnswer = "### Regarding Your Question: \"{$cleanQ}\"

Thank you for asking about **{$cleanQ}**. 

Here is what you should know regarding your query:
Mental health and emotional well-being are influenced by stress, thoughts, sleep, and supportive connections. Taking slow, deep breaths and grounding yourself is a great first step when feeling overwhelmed.
I will assist you with:
  - **Anxiety & Panic Attacks**
  - **Depression & Mood Support**
  - **Academic Stress & Burnout**
  - **Grounding & Breathing Exercises**
  - **Sleep Hygiene & Insomnia**
  - **Self-Assessment & Coping**
* **Speak with a Counselor:** For personalized medical or therapy guidance tailored to your specific situation, please visit the **Book Counselor** tab to schedule a confidential 1-on-1 session with a licensed counselor.";
}

echo json_encode([
    'success' => true,
    'answer' => $matchedAnswer
]);
