<?php
$topic_id = 59;
$topic_name = 'sleep_disorder';

$questions = [

    // 1. Insomnia
    ['tag'=>'insomnia_frequency','question'=>'How often do you have trouble falling or staying asleep?','group'=>'Insomnia','priority'=>'Must'],
    ['tag'=>'insomnia_causes','question'=>'Do you know what factors contribute to your insomnia?','group'=>'Insomnia','priority'=>'Good to Ask'],
    ['tag'=>'insomnia_effects','question'=>'How does insomnia affect your daily life or mood?','group'=>'Insomnia','priority'=>'Must'],

    // 2. Sleep Apnea
    ['tag'=>'apnea_symptoms','question'=>'Do you experience loud snoring, choking, or gasping during sleep?','group'=>'Sleep_Apnea','priority'=>'Must'],
    ['tag'=>'apnea_diagnosis','question'=>'Have you been diagnosed with sleep apnea?','group'=>'Sleep_Apnea','priority'=>'Good to Ask'],
    ['tag'=>'apnea_treatment','question'=>'Do you use any device or treatment to manage sleep apnea?','group'=>'Sleep_Apnea','priority'=>'Optional'],

    // 3. Narcolepsy
    ['tag'=>'narcolepsy_sleepiness','question'=>'Do you experience sudden daytime sleep attacks?','group'=>'Narcolepsy','priority'=>'Must'],
    ['tag'=>'narcolepsy_cataplexy','question'=>'Do you have episodes of sudden muscle weakness triggered by emotions?','group'=>'Narcolepsy','priority'=>'Good to Ask'],
    ['tag'=>'narcolepsy_diagnosis','question'=>'Have you been evaluated for narcolepsy?','group'=>'Narcolepsy','priority'=>'Optional'],

    // 4. Restless Legs Syndrome
    ['tag'=>'rls_urge','question'=>'Do you feel an uncontrollable urge to move your legs at night?','group'=>'Restless_Legs_Syndrome','priority'=>'Must'],
    ['tag'=>'rls_sensation','question'=>'What sensations accompany this urge (tingling, crawling, aching)?','group'=>'Restless_Legs_Syndrome','priority'=>'Good to Ask'],
    ['tag'=>'rls_effects','question'=>'Does this affect your sleep duration or quality?','group'=>'Restless_Legs_Syndrome','priority'=>'Optional'],

    // 5. Circadian Rhythm Disorders
    ['tag'=>'circadian_shift','question'=>'Do you have trouble sleeping at conventional hours?','group'=>'Circadian_Rhythm_Disorders','priority'=>'Must'],
    ['tag'=>'circadian_routine','question'=>'Does your sleep pattern vary on weekends or workdays?','group'=>'Circadian_Rhythm_Disorders','priority'=>'Good to Ask'],
    ['tag'=>'circadian_impact','question'=>'Does your sleep schedule affect daytime functioning?','group'=>'Circadian_Rhythm_Disorders','priority'=>'Optional'],

    // 6. Parasomnias
    ['tag'=>'parasomnia_types','question'=>'Do you experience sleepwalking, night terrors, or talking in sleep?','group'=>'Parasomnias','priority'=>'Must'],
    ['tag'=>'parasomnia_frequency','question'=>'How often do these events occur?','group'=>'Parasomnias','priority'=>'Good to Ask'],
    ['tag'=>'parasomnia_safety','question'=>'Have these episodes caused injuries or disruptions?','group'=>'Parasomnias','priority'=>'Optional'],

    // 7. Sleep-Related Movement Disorders
    ['tag'=>'plmd_movements','question'=>'Do you have involuntary limb movements during sleep?','group'=>'Sleep_Related_Movement','priority'=>'Must'],
    ['tag'=>'plmd_frequency','question'=>'How frequently do these movements occur?','group'=>'Sleep_Related_Movement','priority'=>'Good to Ask'],
    ['tag'=>'plmd_effects','question'=>'Do these movements affect sleep quality or daytime alertness?','group'=>'Sleep_Related_Movement','priority'=>'Optional'],

    // 8. Mental Health & Sleep
    ['tag'=>'sleep_stress','question'=>'Does stress or anxiety affect your sleep?','group'=>'Mental_Health','priority'=>'Must'],
    ['tag'=>'sleep_mood','question'=>'Does poor sleep worsen your mood or mental health?','group'=>'Mental_Health','priority'=>'Good to Ask'],
    ['tag'=>'sleep_treatment','question'=>'Have mental health interventions helped improve your sleep?','group'=>'Mental_Health','priority'=>'Optional'],

    // 9. Pediatric Sleep Disorders
    ['tag'=>'child_sleep_problems','question'=>'Does your child have difficulty falling or staying asleep?','group'=>'Pediatric_Sleep','priority'=>'Must'],
    ['tag'=>'child_breathing_issues','question'=>'Does your child snore or show signs of sleep apnea?','group'=>'Pediatric_Sleep','priority'=>'Good to Ask'],
    ['tag'=>'child_daytime_behavior','question'=>'Does poor sleep affect your child’s behavior or learning?','group'=>'Pediatric_Sleep','priority'=>'Optional'],

    // 10. Sleep Hygiene
    ['tag'=>'sleep_environment','question'=>'Is your bedroom environment conducive to sleep?','group'=>'Sleep_Hygiene','priority'=>'Must'],
    ['tag'=>'sleep_routine','question'=>'Do you have a consistent bedtime and wake-up schedule?','group'=>'Sleep_Hygiene','priority'=>'Good to Ask'],
    ['tag'=>'pre_sleep_behavior','question'=>'Do activities before bed (screen time, caffeine) affect your sleep?','group'=>'Sleep_Hygiene','priority'=>'Optional'],

    // 11. Sleep Medications
    ['tag'=>'sleep_medication_use','question'=>'Do you take medications to help with sleep?','group'=>'Sleep_Medications','priority'=>'Must'],
    ['tag'=>'sleep_medication_effects','question'=>'Do these medications improve or disrupt your sleep?','group'=>'Sleep_Medications','priority'=>'Good to Ask'],
    ['tag'=>'sleep_medication_sideeffects','question'=>'Do you experience side effects from sleep medications?','group'=>'Sleep_Medications','priority'=>'Optional'],

    // 12. Substance Use & Sleep
    ['tag'=>'caffeine_sleep','question'=>'Does caffeine affect your ability to fall asleep?','group'=>'Substance_Use','priority'=>'Must'],
    ['tag'=>'alcohol_sleep','question'=>'Does alcohol consumption affect sleep quality?','group'=>'Substance_Use','priority'=>'Good to Ask'],
    ['tag'=>'other_substances','question'=>'Do other substances influence your sleep patterns?','group'=>'Substance_Use','priority'=>'Optional'],

    // 13. Daytime Sleepiness
    ['tag'=>'daytime_sleepiness','question'=>'Do you feel excessively sleepy during the day?','group'=>'Daytime_Sleepiness','priority'=>'Must'],
    ['tag'=>'daytime_falls_asleep','question'=>'Do you fall asleep unintentionally during daily activities?','group'=>'Daytime_Sleepiness','priority'=>'Good to Ask'],
    ['tag'=>'daytime_alertness','question'=>'Does sleepiness affect work, school, or social interactions?','group'=>'Daytime_Sleepiness','priority'=>'Optional'],

    // 14. Sleep Quality Perception
    ['tag'=>'sleep_satisfaction','question'=>'How satisfied are you with your overall sleep quality?','group'=>'Sleep_Quality','priority'=>'Must'],
    ['tag'=>'sleep_restoration','question'=>'Do you feel restored after a full night’s sleep?','group'=>'Sleep_Quality','priority'=>'Good to Ask'],
    ['tag'=>'sleep_disruption','question'=>'Are there frequent awakenings or disturbances during sleep?','group'=>'Sleep_Quality','priority'=>'Optional'],

    // 15. Physical Health Influences
    ['tag'=>'health_pain_sleep','question'=>'Do chronic pain or medical conditions affect sleep?','group'=>'Physical_Health','priority'=>'Must'],
    ['tag'=>'health_medical_sleep','question'=>'Do medications for other conditions influence your sleep?','group'=>'Physical_Health','priority'=>'Good to Ask'],
    ['tag'=>'health_overall_sleep','question'=>'Does overall physical health impact your sleep quality?','group'=>'Physical_Health','priority'=>'Optional'],

    // 16. Sleep Tracking
    ['tag'=>'sleep_tracking_methods','question'=>'Do you track your sleep using apps, devices, or journals?','group'=>'Sleep_Tracking','priority'=>'Good to Ask'],
    ['tag'=>'sleep_tracking_accuracy','question'=>'Do these tools help you understand sleep patterns?','group'=>'Sleep_Tracking','priority'=>'Optional'],
    ['tag'=>'sleep_tracking_changes','question'=>'Have you changed behaviors based on sleep tracking data?','group'=>'Sleep_Tracking','priority'=>'Optional'],

    // 17. Sleep Goals
    ['tag'=>'sleep_goal_hours','question'=>'How many hours of sleep do you aim for each night?','group'=>'Sleep_Goals','priority'=>'Must'],
    ['tag'=>'sleep_goal_quality','question'=>'Do you aim for specific sleep quality or routines?','group'=>'Sleep_Goals','priority'=>'Good to Ask'],
    ['tag'=>'sleep_goal_changes','question'=>'Have you tried interventions to meet sleep goals?','group'=>'Sleep_Goals','priority'=>'Optional'],

    // 18. Contributing Factors
    ['tag'=>'stress_sleep','question'=>'Does stress or workload affect your sleep?','group'=>'Contributing_Factors','priority'=>'Must'],
    ['tag'=>'environment_sleep','question'=>'Does your environment (noise, light) influence sleep?','group'=>'Contributing_Factors','priority'=>'Good to Ask'],
    ['tag'=>'lifestyle_sleep','question'=>'Do lifestyle habits like exercise, meals, or screen time impact sleep?','group'=>'Contributing_Factors','priority'=>'Optional'],

];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Sleep Disorder - Initial Consultation Questions</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
        textarea { width: 100%; height: 500px; margin-top: 20px; font-family: monospace; }
    </style>
</head>
<body>

<h2>Initial Consultation Questions for Sleep Disorders</h2>

<table>
    <tr>
        <th>#</th>
        <th>Question</th>
        <th>Tag</th>
        <th>Group</th>
        <th>Priority</th>
    </tr>
    <?php foreach ($questions as $index => $q): ?>
    <tr>
        <td><?= $index + 1 ?></td>
        <td><?= htmlspecialchars($q['question']) ?></td>
        <td><?= htmlspecialchars($q['tag']) ?></td>
        <td><?= htmlspecialchars($q['group']) ?></td>
        <td><?= htmlspecialchars($q['priority']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<h3>Generated SQL Inserts</h3>
<textarea readonly>
<?php
foreach ($questions as $q) {
    echo "INSERT INTO hdb_topic_questions (topic_id, topic_name, initial_question, initial_tag, topic_group, priority) VALUES (";
    echo $topic_id . ", ";
    echo "'" . addslashes($topic_name) . "', ";
    echo "'" . addslashes($q['question']) . "', ";
    echo "'" . addslashes($q['tag']) . "', ";
    echo "'" . addslashes($q['group']) . "', ";
    echo "'" . addslashes($q['priority']) . "'";
    echo ");\n";
}
?>
</textarea>

</body>
</html>