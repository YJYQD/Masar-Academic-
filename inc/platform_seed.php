<?php

function seed_data_required_from_counts(int $academicCollegeCount, int $subjectsCount, int $curriculumCount, int $schedulesCount, int $doctorsCount): bool
{
    return $academicCollegeCount === 0
        || $subjectsCount === 0
        || $curriculumCount === 0
        || $schedulesCount === 0
        || $doctorsCount === 0;
}
