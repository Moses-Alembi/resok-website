-- Nominating somebody who is not a ReSoK member.
--
-- A candidate could only be a member with a portal record, because the ballot took a member
-- id and read the name from the register. Members may now put forward anybody, supplying the
-- details themselves.
--
-- The details live on the candidate row rather than becoming a member record. Creating a
-- member_profiles entry for somebody who has not joined would put them in the register, on
-- renewal reminders, and into every count of the membership - a nomination is not an
-- application, and one should not quietly become the other.
--
-- Whether a candidate is external is derived from member_profile_id being NULL rather than
-- stored as a flag. Two columns that must agree eventually disagree; one that is computed
-- cannot.
--
-- Nothing here relaxes who may nominate. The nominator is still checked against the frozen
-- electoral roll: the electorate proposes its own board, whoever it proposes.

ALTER TABLE election_candidates
  ADD COLUMN email VARCHAR(190) NULL AFTER membership_id;

ALTER TABLE election_candidates
  ADD COLUMN phone VARCHAR(40) NULL AFTER email;

ALTER TABLE election_candidates
  ADD COLUMN organisation VARCHAR(190) NULL AFTER phone;

-- How an external nominee's agreement to stand was obtained. They have no portal account to
-- accept in, so the officer records it - and records how, because "the Chair spoke to them on
-- 12 October" is evidence and "somebody said it was fine" is not.
ALTER TABLE election_candidates
  ADD COLUMN acceptance_note VARCHAR(255) NULL AFTER accepted_at;

-- Finds a name already put forward for a post, which is how a duplicate external nomination
-- is caught: an external nominee has no member id to match on.
ALTER TABLE election_candidates
  ADD KEY election_candidates_name (position_id, name);
