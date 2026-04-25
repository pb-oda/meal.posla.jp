-- Demo account reset for Sakura demo environment (eat.posla.jp)
-- Scope: credentials only. No schema changes.

UPDATE users
   SET username = 'owner@test1',
       email = 'owner@test1',
       password_hash = '$2y$12$iuysn.GQ4LGEG.hzuZEAy.Af6hV1THfwV6cGeofg37W19NlPbXtXS'
 WHERE id = 'u-owner-001';

UPDATE users
   SET username = 'manager1@test1',
       email = 'manager1@test1',
       password_hash = '$2y$12$iuysn.GQ4LGEG.hzuZEAy.Af6hV1THfwV6cGeofg37W19NlPbXtXS'
 WHERE id = 'u-manager-001';

UPDATE users
   SET username = 'test1',
       password_hash = '$2y$12$q.jJQseKDTH84o4O0ftfC.gAbKZ7G6owB3gIZ6M6vaxq1UBlOd6wS',
       cashier_pin_hash = '$2y$12$GF0GuU3izRzL4PEYfLO5g.Etr5fBr5TyUt8ZGg1uqF9k9U7cDjSG6'
 WHERE id = 'u-test-001';

UPDATE users
   SET username = 'kds-test',
       password_hash = '$2y$12$gy/zRR7p8Tfuy4.gLpIEgukjXufW7RNeFmPzS2cZCjebjn6DxhSsm'
 WHERE id = '9048ab09-0aac-4b82-a559-4a4c77cd367e';

UPDATE users
   SET username = 'kds-test2',
       password_hash = '$2y$12$gy/zRR7p8Tfuy4.gLpIEgukjXufW7RNeFmPzS2cZCjebjn6DxhSsm'
 WHERE id = '4659df74-d029-4289-bb02-e5bd0364a255';
