#!/bin/bash

# CCMNet Mentorship Metrics Report
# Usage: ./mentorship-metrics.sh
# Or from project root: web/modules/custom/access/modules/ccmnet/scripts/mentorship-metrics.sh

echo "========================================"
echo "CCMNet Mentorship Engagement Metrics"
echo "========================================"
echo "Generated: $(date)"
echo ""

# Check if we're in a DDEV environment
if command -v ddev &> /dev/null && ddev describe &> /dev/null; then
    DRUSH_CMD="ddev drush"
else
    DRUSH_CMD="drush"
fi

# Run the metrics query
$DRUSH_CMD sqlq "
SELECT 'Total Mentorships' as Metric, COUNT(*) as Count 
FROM node_field_data 
WHERE type = 'mentorship_engagement' AND status = 1

UNION ALL

SELECT 'Total Interested Users', COUNT(*) 
FROM node__field_match_interested_users fiu
JOIN node_field_data nfd ON fiu.entity_id = nfd.nid
WHERE nfd.type = 'mentorship_engagement' AND nfd.status = 1

UNION ALL

SELECT 'Unique Interested Users', COUNT(DISTINCT fiu.field_match_interested_users_target_id)
FROM node__field_match_interested_users fiu
JOIN node_field_data nfd ON fiu.entity_id = nfd.nid
WHERE nfd.type = 'mentorship_engagement' AND nfd.status = 1

UNION ALL

SELECT 'Total Mentors', COUNT(*)
FROM node__field_mentor fm
JOIN node_field_data nfd ON fm.entity_id = nfd.nid
WHERE nfd.type = 'mentorship_engagement' AND nfd.status = 1

UNION ALL

SELECT 'Unique Mentors', COUNT(DISTINCT fm.field_mentor_target_id)
FROM node__field_mentor fm
JOIN node_field_data nfd ON fm.entity_id = nfd.nid
WHERE nfd.type = 'mentorship_engagement' AND nfd.status = 1

UNION ALL

SELECT 'Total Mentees', COUNT(*)
FROM node__field_mentee fme
JOIN node_field_data nfd ON fme.entity_id = nfd.nid
WHERE nfd.type = 'mentorship_engagement' AND nfd.status = 1

UNION ALL

SELECT 'Unique Mentees', COUNT(DISTINCT fme.field_mentee_target_id)
FROM node__field_mentee fme
JOIN node_field_data nfd ON fme.entity_id = nfd.nid
WHERE nfd.type = 'mentorship_engagement' AND nfd.status = 1

ORDER BY 
  CASE Metric
    WHEN 'Total Mentorships' THEN 1
    WHEN 'Total Interested Users' THEN 2
    WHEN 'Unique Interested Users' THEN 3
    WHEN 'Total Mentors' THEN 4
    WHEN 'Unique Mentors' THEN 5
    WHEN 'Total Mentees' THEN 6
    WHEN 'Unique Mentees' THEN 7
  END
"

echo ""
echo "========================================"