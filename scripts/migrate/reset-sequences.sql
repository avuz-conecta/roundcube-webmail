SELECT setval('users_seq',               COALESCE((SELECT MAX(user_id)      FROM users), 0) + 1, false);
SELECT setval('identities_seq',          COALESCE((SELECT MAX(identity_id)   FROM identities), 0) + 1, false);
SELECT setval('contacts_seq',            COALESCE((SELECT MAX(contact_id)    FROM contacts), 0) + 1, false);
SELECT setval('contactgroups_seq',       COALESCE((SELECT MAX(contactgroup_id) FROM contactgroups), 0) + 1, false);
SELECT setval('collected_addresses_seq', COALESCE((SELECT MAX(address_id)    FROM collected_addresses), 0) + 1, false);
SELECT setval('responses_seq',           COALESCE((SELECT MAX(response_id)   FROM responses), 0) + 1, false);
