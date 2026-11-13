ALTER TABLE userinfo MODIFY voipmsapipassword VARCHAR(500) NOT NULL;
ALTER TABLE sbchat MODIFY sbchatapitoken VARCHAR(500);
ALTER TABLE sbchat MODIFY masclientid VARCHAR(1000);
ALTER TABLE sbchat MODIFY massecret VARCHAR(1000);
