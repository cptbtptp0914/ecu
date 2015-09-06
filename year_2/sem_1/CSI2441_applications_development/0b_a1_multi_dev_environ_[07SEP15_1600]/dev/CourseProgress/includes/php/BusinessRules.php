<?php
namespace includes;

/**
 * Class BusinessRules contains all the business related logic to
 * calculate the summary of a student's course progress.
 *
 * @package includes
 */
class BusinessRules {

    // import the instance of theStudent and theUnits
    private $theUnits;
    private $theStudent;

    // constants used to calculate summary according to business rules
    const CT_UNDERGRAD = 1;
    const CT_UNDERGRAD_DOUBLE = 2;
    const CT_GRAD_DIPLOMA = 3;
    const CT_MASTERS_COURSE = 4;
    const CT_MASTERS_RESEARCH = 5;

    const CP_UNDERGRAD = 360;
    const CP_UNDERGRAD_DOUBLE = 480;
    const CP_GRAD_DIPLOMA = 120;
    const CP_MASTERS_COURSE = 180;
    const CP_MASTERS_RESEARCH = 240;

    const CP_FULLTIME = 60;
    const CP_PARTTIME = 30;

    const MARK_PASS = 50;
    const MARK_SUP_MIN = 45;
    const MARK_SUP_MAX = 49;
    const MAX_FAILS = 3;

    const EXCLUDED = "Excluded";
    const GOOD_STANDING = "Good standing";
    const GOOD_STANDING_SUP = "Good standing, pending supp";

    // variables for calculating summary

    // credit points
    private $passedCPTotal;
    private $cpDelta;

    // units/semesters
    private $unitAttemptTotal;
    private $unitsPassed;
    private $semRemaining;
    private $semTotal;

    // marks/grades
    private $markTotal;
    private $markAverage;
    private $gradeAverage;

    // progression/complete status
    private $progressionStatus;
    private $isComplete;

    // fails
    private $failedUnitsTally;
    private $failedUnitsCP;
    // supplementary
    private $matchedFailedTally;

    /**
     * The BusinessRules constructor.
     * Accepts theUnits as parameter so we can use Units->getGrade().
     *
     * @param Student $theStudent - The reference to theStudent object.
     * @param Units $theUnits - The reference to theUnits object.
     */
    function __construct(Student $theStudent, Units $theUnits) {

        $this->theStudent = $theStudent;
        $this->theUnits = $theUnits;

        // init variables with defaults
        $this->passedCPTotal = 0;
        $this->cpDelta = 0;

        $this->unitAttemptTotal = 0;
        $this->unitsPassed = 0;
        $this->semRemaining = 0;
        $this->semTotal = 0;

        $this->markTotal = 0;
        $this->markAverage = 0;
        $this->gradeAverage = "";

        $this->progressionStatus = "";
        $this->isComplete = false;

        $this->failedUnitsTally = 0;
        $this->failedUnitsCP = 0;
        $this->matchedFailedTally = 0;
    }

    /**
     * This function initiates the calculation of a student's summary.
     */
    public final function calculateSummary() {

        // call all the things
        $this->setSemTotal();
        $this->iterateUnitDetails();
        $this->setCPDelta();
        $this->setIsComplete();
        $this->setMarkAverage();
        $this->setProgressionStatus();
        $this->setSemRemaining();
        $this->setSupUnit();
    }

    /**
     * This function iterates through the unitDetails array,
     * and performs calculations at each iteration.
     * Some called functions may also perform their own iteration.
     */
    private final function iterateUnitDetails() {

        global $theUnits;

        for($i = 0; $i < sizeof($theUnits->getUnitDetails()); $i++) {

            $this->incrementUnitAttemptTotal();

            // set the grade for this mark
            //$this->unitDetailsArray[$i][Units::GR] = $this->getGrade(intval($this->unitDetailsArray[$i][Units::UM]));
            $theUnits->setUnitGrade($i, $this->getGrade($theUnits->getUnitDetails()[$i][Units::UM]));

            // then store the highest mark found in theUnits->highestMark array
            if($theUnits->getUnitDetails()[$i][Units::UM] > $theUnits->getHighestMark()[Units::UM]) {
                $theUnits->setHighestMark(
                    $theUnits->getUnitDetails()[$i][Units::UC],
                    $theUnits->getUnitDetails()[$i][Units::CP],
                    $theUnits->getUnitDetails()[$i][Units::YS],
                    $theUnits->getUnitDetails()[$i][Units::UM],
                    $theUnits->getUnitDetails()[$i][Units::GR]
                );
            }

            // if student passed unit
            if($theUnits->getUnitDetails()[$i][Units::UM] >= $this::MARK_PASS) {
                $this->setPassedCPTotal($i);
                $this->incrementUnitsPassed();
                $this->setMarkTotal($i);

            } else {
                $this->calculateProgression($i);
            }
        }
    }

    /**
     * This function increments unitAttemptTotal.
     */
    private final function incrementUnitAttemptTotal() {
        $this->unitAttemptTotal++;
    }

    /**
     * This function sums passedCPTotal from unitDetailsArray.
     * To be used inside for loop, @see iterateUnitDetails().
     *
     * @param int $index - The current array index.
     */
    private final function setPassedCPTotal($index) {
        global $theUnits;
        $this->passedCPTotal += $theUnits->getUnitDetails()[$index][Units::CP];
    }

    /**
     * This function increments unitsPassed.
     */
    private final function incrementUnitsPassed() {
        $this->unitsPassed++;
    }

    /**
     * This function sums markTotal from unitDetailsArray.
     * To be used inside for loop, @see iterateUnitDetails().
     *
     * @param int $index - The current array index.
     */
    private final function setMarkTotal($index) {
        global $theUnits;
        $this->markTotal += $theUnits->getUnitDetails()[$index][Units::UM];
    }

    /**
     * This function calculates student's progression.
     * To be used inside for loop, @see iterateUnitDetails().
     *
     * @param int $index - The current array index.
     */
    private final function calculateProgression($index) {
        global $theUnits;

        $currentUnitCode = $theUnits->getUnitDetails()[$index][Units::UC];
        $this->failedUnitsTally++;

        // loop from current index + 1 to remaining entries
        for($i = $index + 1; $i < sizeof($theUnits->getUnitDetails()); $i++) {
            // if unitcodes match and unit is failed,
            if($currentUnitCode == $theUnits->getUnitDetails()[$i][Units::UC] &&
                    $theUnits->getUnitDetails()[$index][Units::UM] < $this::MARK_PASS) {

                // increment matchFailedTally
                $this->matchedFailedTally++;

            }
        }
    }

    /**
     * This function sets the total number of semesters a student requires to complete for their course.
     * Student course type / Student enrolment type.
     */
    private final function setSemTotal() {
        global $theStudent;
        $this->semTotal = $theStudent->getStudentDetails()[Student::CT] / $theStudent->getStudentDetails()[Student::ET];
    }

    /**
     * This function sets the required credit points to complete course.
     * Already stored course type value in studentDetails array,
     * so just subtract passedCPTotal from it.
     *
     * TODO: Remember to convert asp's implementation to this much simpler version as well.
     */
    private final function setCPDelta() {
        global $theStudent;
        $this->cpDelta = $theStudent->getStudentDetails()[Student::CT] - $this->passedCPTotal;
    }

    /**
     * This function sets isComplete, the complete status for a student.
     * If passedCPTotal >= student's course type, then isComplete = true.
     *
     * TODO: Remember to convert asp's implementation to this much simpler version as well.
     */
    private final function setIsComplete() {
        global $theStudent;
        if($this->passedCPTotal >= $theStudent->getStudentDetails()[Student::CT]) {
            $this->isComplete = true;
        } else {
            $this->isComplete = false;
        }
    }

    /**
     * This function calculates average mark over total units attempted.
     * Also sets the grade for the average.
     */
    private final function setMarkAverage() {
        $this->markAverage = $this->markTotal / $this->unitAttemptTotal;
        // go set the grade too
        $this->gradeAverage = $this->getGrade($this->markAverage);
    }

    /**
     * This function sets the progression status.
     * If student fails same unit 3 times or more,
     * status is "Excluded".
     */
    private final function setProgressionStatus() {
        if($this->matchedFailedTally >= $this::MAX_FAILS) {
            $this->progressionStatus = $this::EXCLUDED;
        } else {
            $this->progressionStatus = $this::GOOD_STANDING;
        }
    }

    /**
     * This function sets the number of semesters remaining for a student.
     * If student has no failed units: Divide remaining CP required by student's enrolment type value.
     * Else student has failed a unit: Add remaining CP to failedUnitsCP, then divide by student's enrolment type value.
     *
     * TODO: Remember to convert asp's implementation to this much simpler version as well.
     */
    private final function setSemRemaining() {
        global $theStudent;
        if($this->failedUnitsTally == 0) {
            $this->semRemaining = $this->cpDelta / $theStudent->getStudentDetails()[Student::ET];
        } else {
            $this->semRemaining = ($this->cpDelta + $this->failedUnitsCP) / $theStudent->getStudentDetails()[Student::ET];
        }
    }

    /**
     * This function implements business rule:
     * If a student does more than one unit in a given semester,
     * and fails only one unit with a mark in the range of 45-49,
     * and is in the first or last semester of their course,
     * then the grade for that unit should read "S?" for possible
     * supplementary assessment.
     *
     * !! ASSUMPTIONS/LIMITATIONS !!
     * 1. Only considers 15 CP per unit, not 20
     * 2. User input must be in correct order according to semester!
     *
     * TODO:    Managed to reduce conditional code for fulltime/parttime
     * TODO:    students by pre-calculating the lastSemStart and unitsPerSem.
     * TODO:    Remember to update asp with this change.
     */
    private final function setSupUnit() {

        global $theStudent;
        global $theUnits;

        // assuming user will enter their first sem FIRST!
        $firstSem = $theUnits->getUnitDetails()[0][Units::YS];
        $lastSemStart = 0;
        $lastSem = "";

        $fullTimeUnits = 4;
        $partTimeUnits = 2;
        $unitsPerSem = 0;

        $firstSemFails = 0;
        $lastSemFails = 0;

        $isSup = false;
        $isMultiFirstSem = false;
        $isMultiLastSem = false;

        // work out what the lastSem and unitsPerSem values will be
        // this saves conditional code for fulltime/parttime students
        if($theStudent->getStudentDetails()[Student::ET] == BusinessRules::CP_FULLTIME) {
            $lastSemStart = ($this->semTotal * $fullTimeUnits) - $fullTimeUnits;
            $unitsPerSem = $fullTimeUnits;
        } else {
            $lastSemStart = ($this->semTotal * $partTimeUnits) - $partTimeUnits;
            $unitsPerSem = $partTimeUnits;
        }

        /*************
         * FIRST SEM *
         *************/

        // if student has attempted a unit during first sem,
        if($this->unitAttemptTotal > 0) {
            // first loop sets the flags used for testing
            for($i = 0; $i < $unitsPerSem; $i++) {
                // test for more than one units attempted during first semester
                if($firstSem == $theUnits->getUnitDetails()[$i][Units::YS]) {
                    $isMultiFirstSem = true;
                }
                // test for more than one fails during first semester
                if($theUnits->getUnitDetails()[$i][Units::UM] < $this::MARK_PASS) {
                    $firstSemFails++;
                }
            }
            // second loop uses flags to determine if eligible for "S?" grade
            for($i = 0; $i < $unitsPerSem; $i++) {
                if($isMultiFirstSem && $firstSemFails < 2 &&
                        $theUnits->getUnitDetails()[$i][Units::UM] >= $this::MARK_SUP_MIN &&
                        $theUnits->getUnitDetails()[$i][Units::UM] <= $this::MARK_SUP_MAX) {

                    $theUnits->setUnitGrade($i, "S?");
                    $isSup = true;
                }
            }
        }

        /************
         * LAST SEM *
         ************/

        // if student has attempted a unit during last sem,
        if($this->unitAttemptTotal >= $lastSemStart) {
            // set the last sem as the last input from the user
            $lastSem = $theUnits->getUnitDetails()[$this->unitAttemptTotal - 1][Units::YS];
            // first loop sets the flags used for testing
            for($i = $lastSemStart + 1; $i < sizeof($theUnits->getUnitDetails()); $i++) {
                // test for more than one units attempted during last semester
                if($lastSem == $theUnits->getUnitDetails()[$i - 1][Units::YS]) {
                    $isMultiLastSem = true;
                }
                // test for more than one fails during last semester
                if($theUnits->getUnitDetails()[$i][Units::UM] < $this::MARK_PASS) {
                    $lastSemFails++;
                }
            }
            // second loop uses flags to determine if eligible for "S?" grade
            for($i = $lastSemStart; $i < sizeof($theUnits->getUnitDetails()); $i++) {
                if($isMultiLastSem && $lastSemFails < 2 &&
                        $theUnits->getUnitDetails()[$i][Units::UM] >= $this::MARK_SUP_MIN &&
                        $theUnits->getUnitDetails()[$i][Units::UM] <= $this::MARK_SUP_MAX) {

                    $theUnits->setUnitGrade($i, "S?");
                    $isSup = true;
                }
            }
        }

        /*************************
         * POST S? DETERMINATION *
         *************************/

        // if same unit has x3 fails, but are supp, then student is still in good standing
        if($isSup && $this->progressionStatus = $this::EXCLUDED) {
            $this->progressionStatus = $this::GOOD_STANDING_SUP;
        }
    }

    /**
     * This function determines the grade of a mark.
     *
     * @param int $mark - The mark to grade.
     * @return string - The grade.
     */
    public function getGrade($mark) {

        $grade = "";
        // make sure its int
        $test = intval($mark);

        switch($test) {
            case $test < 60:
                $grade = "C";
                break;
            case $test < 70:
                $grade = "CR";
                break;
            case $test < 80:
                $grade = "D";
                break;
            case $test <= 100:
                $grade = "HD";
                break;
            default:
                $grade = "N";
        }

        return $grade;
    }

    /**
     * This function returns the student's progression status.
     *
     * @return string progressionStatus.
     */
    public function getProgressionStatus() {
        return $this->progressionStatus;
    }

    /**
     * This function returns the boolean isComplete.
     * Finished course == true,
     * Not finished course == false
     *
     * @return bool isComplete.
     */
    public function isComplete() {
        return $this->isComplete;
    }

    /**
     * This function returns passedCPTotal.
     *
     * @return int passedCPTotal.
     */
    public function getPassedCP() {
        return $this->passedCPTotal;
    }

    /**
     * This function returns cpDelta.
     *
     * @return int cpDelta.
     */
    public function getCPDelta() {
        return $this->cpDelta;
    }

    /**
     * This function returns unitAttemptTotal.
     *
     * @return int unitAttemptTotal.
     */
    public function getUnitsAttempted() {
        return $this->unitAttemptTotal;
    }

    /**
     * This function returns unitsPassed.
     *
     * @return int unitsPassed.
     */
    public function getUnitsPassed() {
        return $this->unitsPassed;
    }

    /**
     * This function returns semRemaining.
     *
     * @return int semRemaining.
     */
    public function getSemRemaining() {
        return $this->semRemaining;
    }

    /**
     * This function returns markAverage.
     *
     * @return int markAverage.
     */
    public function getMarkAverage() {
        return $this->markAverage;
    }

    /**
     * This function imports the input arrays from the Student and Units class.
     * This must occur post validation by Validator.
     *
     * Once input arrays have been imported, it will kick off calculation of the summary.
     *
     * @param array $studentDetails
     * @param array $unitDetails
     */
    public final function setInputArrays(array $studentDetails, array $unitDetails) {
        $this->studentDetailsArray = $studentDetails;
//        $this->unitDetailsArray = $unitDetails;
        // now that we have all the input data, we can start validation
        $this->calculateSummary();
    }
}

?>