<?php
/**
 * Get Combined Self-Assessment Questions API
 * Returns a single questionnaire containing 15 short, interesting questions.
 */

require_once '../config/session.php';

// Enforce student role
enforce_auth(['student']);

$questions = [
    // Anxiety
    [
        'id' => 1,
        'text' => 'Do you often feel on edge, restless, or unable to sit still?',
        'disorder' => 'anxiety',
        'original_id' => 1
    ],
    [
        'id' => 2,
        'text' => 'Do you find it difficult to stop or control your worrying?',
        'disorder' => 'anxiety',
        'original_id' => 2
    ],
    [
        'id' => 3,
        'text' => 'Does worry keep you from enjoying everyday activities?',
        'disorder' => 'anxiety',
        'original_id' => 3
    ],
    // Depression
    [
        'id' => 4,
        'text' => 'Have you lost interest or pleasure in things you usually enjoy?',
        'disorder' => 'depression',
        'original_id' => 1
    ],
    [
        'id' => 5,
        'text' => 'Do you feel constantly tired, drained, or low on energy?',
        'disorder' => 'depression',
        'original_id' => 2
    ],
    [
        'id' => 6,
        'text' => 'Do you feel down, depressed, or hopeless about the future?',
        'disorder' => 'depression',
        'original_id' => 3
    ],
    // Bipolar
    [
        'id' => 7,
        'text' => 'Do you experience sudden bursts of high energy where you sleep less but feel highly active?',
        'disorder' => 'bipolar',
        'original_id' => 1
    ],
    [
        'id' => 8,
        'text' => 'Does your mood switch rapidly from extremely excited to very irritable?',
        'disorder' => 'bipolar',
        'original_id' => 2
    ],
    [
        'id' => 9,
        'text' => 'Do you find your thoughts racing so fast that your mind won\'t slow down?',
        'disorder' => 'bipolar',
        'original_id' => 3
    ],
    // PTSD
    [
        'id' => 10,
        'text' => 'Do you have nightmares or unwanted memories of a stressful event?',
        'disorder' => 'ptsd',
        'original_id' => 1
    ],
    [
        'id' => 11,
        'text' => 'Do you avoid reminders or situations that recall past painful experiences?',
        'disorder' => 'ptsd',
        'original_id' => 2
    ],
    // Stress
    [
        'id' => 12,
        'text' => 'Do you feel overwhelmed, like difficulties are piling up too high to overcome?',
        'disorder' => 'stress',
        'original_id' => 1
    ],
    [
        'id' => 13,
        'text' => 'Do you feel unable to control the important aspects of your daily life?',
        'disorder' => 'stress',
        'original_id' => 2
    ],
    // Self-Esteem
    [
        'id' => 14,
        'text' => 'Do you often compare yourself to others and feel like you are a failure?',
        'disorder' => 'self_esteem',
        'original_id' => 1
    ],
    [
        'id' => 15,
        'text' => 'Is it hard for you to see your own worth or feel good about yourself?',
        'disorder' => 'self_esteem',
        'original_id' => 2
    ]
];

$title = 'Combined Mental Health Self-Assessment';
$subtitle = 'Answer 15 quick questions covering your symptoms.';

$options = [
    ['value' => 0, 'text' => 'Not at all'],
    ['value' => 1, 'text' => 'Several days'],
    ['value' => 2, 'text' => 'More than half the days'],
    ['value' => 3, 'text' => 'Nearly every day']
];

send_json_response(true, 'Questions retrieved successfully.', [
    'title' => $title,
    'subtitle' => $subtitle,
    'questions' => $questions,
    'options' => $options
]);
