-- Migration 1195: Mia as HubSpot — email (calendar, watering stage, sequences, send times, results)
-- Date: 2026-10-06
-- Purpose:
--   mia_calendar          a full Lower Mainland year of email campaigns Mia proposes (~7 days
--                         before each send window). Tim can edit dates, words and conditions here;
--                         MiaCalendar::defaults() is the same seed in PHP.
--   mia_campaigns +       calendar_key, season_year, send window, the frozen follow-up words
--                         (sequence_json — Tim's approval covers them), follow-up campaign ids
--                         (sequence_state_json) and copy flags.
--   campaign_sends +      step (0 main, 1 reminder, 2 last call), parent_send_id, scheduled_at
--                         (the sender only sends rows whose time has come), replied_at, booked_at.
--   campaign_events       every open/click with time and user agent (send-time learning; Apple
--                         Mail Privacy Protection opens are filtered out when learning).
--   mia_send_times        each contact's learned best weekday/hour (recomputed nightly).
--   mia_calendar_results  each calendar campaign's results by year (sent, clicked, replied,
--                         quoted, booked, booked $).
-- Plain ALTERs (no ADD COLUMN IF NOT EXISTS — MariaDB-only). Run once. Code is guarded: before
-- this runs Mia proposes the v1 post-drought campaign exactly as before, and the sender ignores
-- scheduled_at.
-- MySQL 5.7 compatible: TEXT for JSON, no JSON functions, no generated columns, no window functions.

CREATE TABLE IF NOT EXISTS mia_calendar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cal_key VARCHAR(40) NOT NULL         COMMENT 'e.g. fall_lawn_main — proposals are keyed <cal_key>_<year>',
  name VARCHAR(120) NOT NULL,
  audience VARCHAR(80) NOT NULL         COMMENT 'homeowners | property_managers | stratas | all_clients | past_seed | spring_holds (comma for several)',
  propose_on CHAR(5) NOT NULL           COMMENT 'MM-DD Mia proposes it (later than send_from = the year before)',
  send_from CHAR(5) NOT NULL            COMMENT 'MM-DD send window opens',
  send_to CHAR(5) NOT NULL              COMMENT 'MM-DD send window closes (earlier than send_from = next year)',
  work_window VARCHAR(80) NULL          COMMENT 'When the work itself happens (words only)',
  services VARCHAR(200) NULL            COMMENT 'Comma list: aeration, overseed, topdress, hedge, cleanup, mulch, snow, salt — {price:x} comes from products',
  conditions_json TEXT NULL             COMMENT '{"lawn_seeding":true,"requires_restriction_stage_max":1,"skip_if_leaf_heavy":true,"requires_drought_year":true,"exclude_responders_of":"<cal_key>","stage_aware":true}',
  why TEXT NULL,
  subject VARCHAR(200) NOT NULL,
  body_text TEXT NOT NULL               COMMENT 'Tim''s words; {{first_name}} per person, {price:x} at proposal time',
  reminder_days INT NULL                COMMENT 'Reminder this many days after each person''s main email (non-responders only)',
  reminder_subject VARCHAR(200) NULL,
  reminder_body TEXT NULL,
  lastcall_days INT NULL,
  lastcall_subject VARCHAR(200) NULL,
  lastcall_body TEXT NULL,
  photo_pattern VARCHAR(120) NULL       COMMENT 'Regex picking Tim''s own published before/after pair',
  label VARCHAR(80) NULL                COMMENT 'How Mia names it in a brief: "the fall lawn email"',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mia_calendar_key (cal_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia''s email calendar — editable; nothing sends without Tim''s approval';
INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'spring_early_bird',
  'Spring lawn early-bird',
  'homeowners',
  '01-05',
  '01-12',
  '01-23',
  'April',
  'aeration,overseed',
  '{"lawn_seeding":true}',
  'Spring is the busiest season and April is the window for aeration and overseeding. Homeowners who book in January get the April timeslots.',
  'Spring lawn work, booked ahead',
  'Hi {{first_name}},

Spring is our busiest season, and the lawns that come back best are the ones aerated and overseeded in April, once the soil warms up and before the watering restrictions start on May 1.

So I take spring lawn work booked ahead. If you''d like yours on the list, reply to this email and I''ll hold an April timeslot for you. I''ll measure the lawn and send a fixed price before any work starts.

Aeration starts at {price:aeration} and overseeding at {price:overseed}. Both go by the size of the lawn.

Thanks,
Tim',
  10,
  'April timeslots',
  'Hi {{first_name}},

A short follow-up to my note about spring lawn work. I hold April timeslots in the order people reply. If you''d like one for your lawn, reply "spring" and I''ll put you down.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  '/aerat|overseed|lawn|turf|grass/i',
  'the spring early-bird email',
  10);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'spring_holds',
  'Spring holds — confirm dates',
  'spring_holds',
  '01-05',
  '01-12',
  '01-30',
  'April',
  'aeration,overseed',
  NULL,
  'Everyone who replied "spring" last fall asked for a timeslot held. January is when to turn that into a date.',
  'Your spring timeslot',
  'Hi {{first_name}},

In the fall you asked me to hold a spring timeslot for your lawn. I haven''t forgotten.

Aeration and overseeding go in best in April, once the soil warms up and before the watering restrictions start on May 1. Reply with the weeks that suit you and I''ll confirm a date. I''ll measure the lawn and send a fixed price before any work starts.

Thanks,
Tim',
  10,
  'Your spring timeslot',
  'Hi {{first_name}},

Following up on my note about your spring timeslot. Reply with a week in April that suits you and I''ll confirm it.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  NULL,
  'the spring timeslot email',
  20);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'hedge_before_nesting',
  'Hedges before nesting season',
  'all_clients',
  '02-01',
  '02-08',
  '02-20',
  'before March 15',
  'hedge',
  NULL,
  'Nesting season starts March 15 (BC Wildlife Act s.34). A trim before then needs no nest check.',
  'Hedges before March 15',
  'Hi {{first_name}},

From March 15 to August 15 birds nest in hedges, and BC''s Wildlife Act protects active nests. A hard trim in that window needs a nest check first, and if there''s a nest, the hedge waits.

The simple way round it is to trim before March 15. The hedge is dormant, the shape is easy to see, and it grows back clean in spring.

If you''d like yours done before then, reply to this email and I''ll send a price. Hedge trimming starts at {price:hedge}.

Thanks,
Tim',
  9,
  'Hedges before March 15',
  'Hi {{first_name}},

A short reminder from my last email: hedges need trimming before March 15, when nesting season starts. If you''d like yours done in time, reply and I''ll fit it in.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  '/hedge|shrub/i',
  'the hedge email',
  30);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'spring_lawn_main',
  'Spring aeration and overseeding',
  'homeowners',
  '02-17',
  '02-24',
  '03-06',
  'April to early May',
  'aeration,overseed,topdress',
  '{"lawn_seeding":true}',
  'April to early May is the best window for aeration, overseeding and top-dressing (soil 10–15 °C; March is too wet). Sent 3–4 weeks ahead.',
  'Spring aeration and overseeding',
  'Hi {{first_name}},

April is the best month of the year for a tired lawn. The soil warms enough for new seed to take, and there''s still enough rain to keep it going. March is usually too wet, and from May 1 the watering restrictions make new seed hard to keep alive.

What we do:
- Aeration opens up compacted soil so rain and air reach the roots.
- Overseeding fills the thin and bare patches.
- Top-dressing goes on with the seed: a thin layer of compost that holds moisture.
- Lime, if the soil needs it, goes on at least a month before the seed.

Aeration starts at {price:aeration} and overseeding at {price:overseed}. Both go by the size of your lawn, so I''ll measure yours and send a fixed price before any work starts.

If you''d like an April timeslot, reply to this email and I''ll get back to you within a day.

Thanks,
Tim',
  12,
  'April lawn timeslots',
  'Hi {{first_name}},

Following up on my email about spring aeration and overseeding. April timeslots go in the order people reply. If you''d like one for your lawn, reply to this email.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  '/aerat|overseed|top.?dress|lawn|turf|grass/i',
  'the spring lawn email',
  40);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'spring_lawn_last_chance',
  'Spring lawns — seed by mid-April',
  'homeowners',
  '03-10',
  '03-17',
  '03-25',
  'early April',
  'aeration,overseed',
  '{"lawn_seeding":true,"exclude_responders_of":"spring_lawn_main"}',
  'Metro Vancouver advises seeding by mid-April so it roots before May 1. Goes to homeowners who didn''t answer the spring lawn email.',
  'Seed by mid-April',
  'Hi {{first_name}},

One last note on spring lawns. New seed needs about three weeks of steady moisture to take. Metro Vancouver''s advice is to seed by mid-April, so it''s rooted before the watering restrictions start on May 1.

If you''d like your lawn aerated and overseeded in time, reply to this email and I''ll send a fixed price. Aeration starts at {price:aeration} and overseeding at {price:overseed}.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  'the seed-by-mid-April email',
  50);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'moss_lawn_care',
  'Moss and spring lawn care',
  'homeowners',
  '03-31',
  '04-07',
  '04-18',
  'April and May',
  'aeration',
  '{"stage_aware":true}',
  'Moss follows a wet winter and shade. April is when it shows, and when the fix (drainage, lime, overseeding) works.',
  'Moss in the lawn',
  'Hi {{first_name}},

After a wet winter, moss takes over the shady, compacted parts of a lawn. Raking it out only works for a season if nothing changes underneath.

What changes it: aeration so the soil drains, lime where the soil is sour, and overseeding so grass fills the space the moss leaves. In the shadiest corners, a bed or a shade-tolerant seed mix often does better than lawn.

Aeration starts at {price:aeration}. Reply to this email and I''ll look at your lawn and tell you what it actually needs.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  '/moss|lawn|turf|grass/i',
  'the moss email',
  60);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'beds_mulch',
  'Beds and mulch',
  'homeowners',
  '04-24',
  '05-01',
  '05-12',
  'May',
  'mulch',
  NULL,
  'Mulch keeps beds cool and weed-free through the watering restrictions. Sold in whole yards, 2-yard minimum.',
  'Mulch for the beds',
  'Hi {{first_name}},

A layer of mulch on the garden beds does three jobs through summer: it keeps the soil cool, holds in what moisture there is, and keeps the weeds down.

We weed and edge the beds first, then spread the mulch about two inches deep. Mulch is sold in whole yards, with a two-yard minimum, at {price:mulch} a yard. I''ll tell you how many yards your beds take before any work starts.

If you''d like it done in May, reply to this email.

Thanks,
Tim',
  10,
  'Mulch for the beds',
  'Hi {{first_name}},

Following up on my note about mulching the beds before summer. If you''d like a price for yours, reply to this email.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  '/mulch|bed|garden/i',
  'the mulch email',
  70);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'pm_snow_preseason',
  'Snow and salt — pre-season',
  'property_managers,stratas',
  '06-08',
  '06-15',
  '07-10',
  'November to March',
  'snow,salt',
  NULL,
  'Strata and commercial winter contracts are settled by October 1. Early summer is when budgets for them are drawn up.',
  'Snow and salt for this winter',
  'Hi {{first_name}},

It''s early to think about snow, but strata and commercial winter contracts are mostly settled by October 1, and the sites that get through a cold snap well are the ones with a plan in place before it.

What we do: salting and snow clearing for walkways, entrances and parking areas, with a time-stamped photo after every visit, so you have a record if a slip claim ever comes in. We carry $5M liability insurance and WorkSafeBC coverage.

If you''d like prices for any of your buildings, reply with the addresses and I''ll send a number for each site.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  'the snow and salt email',
  80);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'fall_lawn_homeowners',
  'Fall aeration and overseeding — early word',
  'homeowners',
  '08-08',
  '08-15',
  '08-26',
  'mid-September to mid-October',
  'aeration,overseed',
  '{"lawn_seeding":true,"requires_restriction_stage_max":1,"skip_if_leaf_heavy":true}',
  'Mid-September to mid-October is the fall window for aeration and overseeding. Held while Stage 2/3 restrictions are on; leaf-heavy lawns are left for spring.',
  'Fall aeration and overseeding',
  'Hi {{first_name}},

The second-best time of year for a lawn is coming up. From mid-September to mid-October the soil is still warm, the rain is coming back, and new seed has time to root before winter.

We aerate, overseed and top-dress in one visit. Aeration starts at {price:aeration} and overseeding at {price:overseed}, both by the size of the lawn. I''ll measure yours and send a fixed price before any work starts.

If you''d like a timeslot in that window, reply to this email.

Thanks,
Tim',
  10,
  'Fall lawn timeslots',
  'Hi {{first_name}},

A short follow-up to my email about fall aeration and overseeding. If you''d like your lawn done between mid-September and mid-October, reply to this email.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  '/aerat|overseed|lawn|turf|grass/i',
  'the fall lawn email',
  90);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'hedges_post_nesting',
  'Hedges after nesting season',
  'all_clients',
  '08-10',
  '08-17',
  '08-31',
  'late August to October',
  'hedge',
  NULL,
  'Nesting season ends August 15, so hedges can be trimmed again without a nest check.',
  'Hedges after nesting season',
  'Hi {{first_name}},

Nesting season ends on August 15, so hedges can get a proper trim again without a nest check first. A trim in late summer holds its shape through winter.

Hedge trimming starts at {price:hedge}. If you''d like yours done, reply to this email and I''ll send a price for your property.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  '/hedge|shrub/i',
  'the late-summer hedge email',
  100);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'pm_snow_signup',
  'Snow and salt — sign-up before October 1',
  'property_managers,stratas',
  '08-25',
  '09-01',
  '09-24',
  'November to March',
  'snow,salt',
  NULL,
  'Strata and property managers sign winter contracts before October 1.',
  'Winter snow and salt, before October 1',
  'Hi {{first_name}},

Most strata and commercial winter contracts are signed before October 1, so I''m putting this year''s snow and salt routes together now.

What you get: salting and snow clearing for walkways, entrances and parking areas, a time-stamped photo after every visit, $5M liability insurance and WorkSafeBC coverage. If a slip claim ever comes in, you have the record.

Reply with the sites you''d like priced and I''ll send a number for each, laid out so you can forward it to the council or owners.

Thanks,
Tim',
  10,
  'Snow and salt before October 1',
  'Hi {{first_name}},

Following up on my email about snow and salt for this winter. If you''d like prices for your sites before October 1, reply with the addresses.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  NULL,
  'the snow sign-up email',
  110);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'fall_lawn_main',
  'October lawn renovation + bulbs',
  'all_clients',
  '09-08',
  '09-15',
  '09-24',
  'October',
  'aeration,overseed,topdress',
  '{"lawn_seeding":true,"requires_restriction_stage_max":1}',
  'The main fall campaign: book October aeration, overseeding and top-dressing (and bulbs). Sent 3–4 weeks ahead, with a reminder and a last call.',
  'Your lawn this October',
  'Hi {{first_name}},

October is when we bring lawns back after summer. The rain is back, the soil is still warm, and seed put down now is rooted before winter. A lawn that goes into winter thin usually comes out of it full of moss.

Three things do most of the work:
- Aeration opens up compacted soil so rain and air reach the roots.
- Overseeding fills the thin and bare patches.
- Top-dressing goes on with the seed: a thin layer of compost that holds moisture and feeds it.

One thing to know first: if your lawn sits under trees, falling leaves will smother new seed. For those lawns we aerate now and overseed in spring.

Aeration starts at {price:aeration} and overseeding at {price:overseed}. I''ll measure your lawn and send a fixed price before any work starts. October is also the month to plant spring bulbs, if you''d like some in the beds.

Reply to this email and I''ll get back to you within a day.

Thanks,
Tim',
  14,
  'Your October lawn timeslot',
  'Hi {{first_name}},

A short follow-up to my email about aeration and overseeding. October timeslots go in the order people reply. If you''d like your lawn done before winter, reply to this email.

Thanks,
Tim',
  23,
  'Last call for fall overseeding',
  'Hi {{first_name}},

This is the last week I can book fall overseeding. After mid-October the soil cools and new seed doesn''t root in time. Aeration on its own still helps through November. Reply if you''d like either.

Thanks,
Tim',
  '/aerat|overseed|top.?dress|lawn|turf|grass/i',
  'the October lawn email',
  120);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'post_drought',
  'Post-drought lawn recovery',
  'all_clients',
  '10-01',
  '10-01',
  '10-31',
  'second half of October',
  'aeration,overseed',
  '{"requires_drought_year":true}',
  'The lawn watering restrictions lift on October 15. With the fall rain coming, the second half of October is the window for aeration, overseeding and top-dressing.',
  'Your lawn after the watering ban',
  'Hi {{first_name}},

A lot of lawns came through this summer brown and thin. The watering restrictions lift on October 15, and with the fall rain on its way, the second half of October is the right time to bring yours back. A lawn that goes into winter thin usually comes out of it full of moss and weeds.

Three things do most of the work:
- Aeration opens up compacted soil so rain and air reach the roots.
- Overseeding fills the thin and bare patches before winter.
- Top-dressing goes on with the seed: a thin layer of compost that holds moisture and feeds it.

One thing to know first: new seed needs light. If your lawn sits under trees, falling leaves will smother the seed before it takes. For those lawns we aerate now, and overseed and top-dress in spring once the leaves are done.

What it costs: aeration starts at $95 and overseeding at $90. Both go by the size of your lawn, so I''ll measure yours, tell you what it actually needs, and send a fixed price before any work starts. Top-dressing depends on how much compost it takes, and goes in the same quote. You don''t need to be home, and you''ll get photos of the finished work.

If you''d like us to look at your lawn, reply to this email and I''ll get back to you within a day.

Thanks,
Tim

P.S. Spring is our busiest season, so we only take spring work that''s booked ahead. If you''d rather do it all in spring, reply "spring" and I''ll hold a timeslot open for you whilst we work out the details together.',
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  '/aerat|overseed|top.?dress|lawn|turf|grass/i',
  'the fall lawn email',
  130);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'fall_cleanup',
  'Leaves, fall cleanup and blow-outs',
  'all_clients',
  '10-01',
  '10-08',
  '10-20',
  'late October to December',
  'cleanup',
  NULL,
  'Leaves fall October to December (city collection runs then; leaves blown into the street can be fined). Irrigation blow-outs late October to early November.',
  'Leaves, fall cleanup and sprinkler blow-outs',
  'Hi {{first_name}},

The leaves start coming down properly this month and keep coming until December. Left on the lawn, they smother the grass and it comes out of winter thin and mossy. Blowing them into the street isn''t the answer either: the city collects leaves from October to December and can fine for leaves left in the road.

What we do: clear the leaves from the lawn and beds, cut back what''s finished for the year, and take everything away. Fall cleanup starts at {price:cleanup}.

If you have an irrigation system, late October to early November is the time to blow it out before the first frost. We can do both on the same visit.

Reply to this email and I''ll send a price for your property.

Thanks,
Tim',
  12,
  'Fall cleanup',
  'Hi {{first_name}},

Following up on my email about leaves and fall cleanup. If you''d like your property cleared before winter, reply and I''ll send a price.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  '/clean|leaf|leaves|fall/i',
  'the fall cleanup email',
  140);

INSERT IGNORE INTO mia_calendar (cal_key, name, audience, propose_on, send_from, send_to, work_window, services, conditions_json, why, subject, body_text,
  reminder_days, reminder_subject, reminder_body, lastcall_days, lastcall_subject, lastcall_body, photo_pattern, label, sort_order) VALUES (
  'pm_next_year',
  'Next year''s contracts (strata budgets)',
  'property_managers,stratas',
  '11-03',
  '11-10',
  '11-28',
  'next season',
  NULL,
  NULL,
  'Strata budgets are approved at the AGM within two months of fiscal year-end; numbers need to reach the PM 2–3 months before it.',
  'Next year''s grounds maintenance numbers',
  'Hi {{first_name}},

Most strata councils approve next year''s budget at the AGM, within two months of their fiscal year-end. If any of your buildings has a year-end coming up, I can send next season''s grounds maintenance numbers now, so they go into the budget rather than arriving after it.

Each site gets one number for the year, the visits it covers, and a photo report after every visit. Laid out so you can forward it to the council as it is.

Reply with the buildings and their year-ends and I''ll send the numbers.

Thanks,
Tim',
  14,
  'Numbers for next year''s budget',
  'Hi {{first_name}},

Following up on my email about next season''s numbers. If a council''s year-end is coming up, reply with the building and I''ll get them to you in time for the budget.

Thanks,
Tim',
  NULL,
  NULL,
  NULL,
  NULL,
  'the next-year contracts email',
  150);

ALTER TABLE campaign_sends
  ADD COLUMN step TINYINT NOT NULL DEFAULT 0 COMMENT '0 main email, 1 reminder, 2 last call',
  ADD COLUMN parent_send_id INT NULL COMMENT 'The main send a follow-up belongs to',
  ADD COLUMN scheduled_at DATETIME NULL COMMENT 'Not sent before this (the recipient''s best time); NULL = as soon as possible',
  ADD COLUMN replied_at DATETIME NULL COMMENT 'First inbound reply after this send (stamped nightly)',
  ADD COLUMN booked_at DATETIME NULL COMMENT 'Quote accepted or plan started after this send (stamped nightly)';

CREATE INDEX idx_cs_due ON campaign_sends (status, scheduled_at);
CREATE INDEX idx_cs_parent ON campaign_sends (parent_send_id);

ALTER TABLE mia_campaigns
  ADD COLUMN calendar_key VARCHAR(40) NULL COMMENT 'mia_calendar.cal_key',
  ADD COLUMN season_year SMALLINT NULL,
  ADD COLUMN send_from DATE NULL,
  ADD COLUMN send_to DATE NULL,
  ADD COLUMN sequence_json TEXT NULL COMMENT 'Follow-up words frozen at proposal: [{step, days, subject, body}]',
  ADD COLUMN sequence_state_json TEXT NULL COMMENT 'Follow-up marketing_campaigns ids by step',
  ADD COLUMN flags_json TEXT NULL COMMENT 'Copy problems / watering notes found at proposal';

CREATE INDEX idx_mia_campaigns_cal ON mia_campaigns (calendar_key, season_year);

-- The v1 campaign keeps its history and shows up in results by year.
UPDATE mia_campaigns
   SET calendar_key = 'post_drought',
       season_year = CAST(RIGHT(campaign_key, 4) AS UNSIGNED),
       send_from = CONCAT(RIGHT(campaign_key, 4), '-10-01'),
       send_to = CONCAT(RIGHT(campaign_key, 4), '-10-31')
 WHERE campaign_key LIKE 'post_drought_%' AND calendar_key IS NULL;

CREATE TABLE IF NOT EXISTS campaign_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  send_id INT NOT NULL              COMMENT 'campaign_sends.id',
  kind VARCHAR(10) NOT NULL         COMMENT 'open | click',
  at DATETIME NOT NULL,
  user_agent VARCHAR(255) NULL,
  INDEX idx_ce_send (send_id, kind),
  INDEX idx_ce_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Campaign opens and clicks with time and user agent (max 20 of each per send)';

CREATE TABLE IF NOT EXISTS mia_send_times (
  contact_id INT NOT NULL PRIMARY KEY,
  best_dow TINYINT NOT NULL         COMMENT 'ISO weekday 1 Mon … 7 Sun',
  best_hour TINYINT NOT NULL        COMMENT '0–23, America/Vancouver',
  weight DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'Evidence: reply 3, click 2, believable open 0.5',
  events INT NOT NULL DEFAULT 0,
  computed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Each contact''s learned best time to receive a campaign email';

CREATE TABLE IF NOT EXISTS mia_calendar_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  calendar_key VARCHAR(40) NOT NULL,
  season_year SMALLINT NOT NULL,
  mia_campaign_id INT NULL,
  sent INT NOT NULL DEFAULT 0,
  clicked INT NOT NULL DEFAULT 0,
  replied INT NOT NULL DEFAULT 0,
  quoted INT NOT NULL DEFAULT 0,
  booked INT NOT NULL DEFAULT 0,
  booked_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_mcr (calendar_key, season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='What each calendar campaign earned, by year (bookings first)';

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES
  ('mia_send_default_pm', '2,3,4@09:30', 'Mia: default send slots for property managers / stratas (ISO weekdays@time; ; between groups)'),
  ('mia_send_default_home', '2,3@19:30;6@09:00', 'Mia: default send slots for homeowners (ISO weekdays@time; ; between groups)');
