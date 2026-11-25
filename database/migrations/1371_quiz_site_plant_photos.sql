-- 1371 — quiz questions learn from our own plant photos (2026-10-16, Tim: "need to learn faster with our photos")
--
-- quiz_questions.plant_key       the plant a question is about (lower-case scientific name), so a confirmed plant
--                                finds its question without guessing from text (SitePlantQuizService::linkQuestions)
-- quiz_question_images.*         a site photo's origin: which plant record, property and media it came from, so the
--                                newest few per question are kept and the pre-shift quiz can favour today's sites

ALTER TABLE quiz_questions
    ADD COLUMN plant_key VARCHAR(160) NULL COMMENT 'lower-case scientific name of the plant this question is about' AFTER category_id;
CREATE INDEX idx_quiz_questions_plant_key ON quiz_questions (plant_key);

ALTER TABLE quiz_question_images
    ADD COLUMN source VARCHAR(10) NULL COMMENT 'site = a crew photo from a property, NULL = uploaded in quiz admin' AFTER caption,
    ADD COLUMN plant_record_id INT NULL AFTER source,
    ADD COLUMN property_id INT NULL AFTER plant_record_id,
    ADD COLUMN media_id INT NULL AFTER property_id;
CREATE INDEX idx_quiz_images_site ON quiz_question_images (source, property_id);
CREATE INDEX idx_quiz_images_record ON quiz_question_images (plant_record_id);
