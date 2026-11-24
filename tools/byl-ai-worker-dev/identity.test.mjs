import {test} from 'node:test';
import assert from 'node:assert/strict';
import {canonicalIdentity as c} from './identity.mjs';
test('instagram URL wins over model guesses',()=>assert.deepEqual(c('Instagram https://instagram.com/demo_mixed 12K followers.'),{platform:'instagram',username:'demo_mixed'}));
test('YouTube handle strips @',()=>assert.deepEqual(c('https://www.youtube.com/@demo_video 1.2K subscribers'),{platform:'youtube',username:'demo_video'}));
test('X normalized to twitter',()=>assert.deepEqual(c('https://x.com/demo_es 29.4K'),{platform:'twitter',username:'demo_es'}));
test('ambiguous handles not chosen',()=>assert.equal(c('https://x.com/one https://instagram.com/two'),null));
test('non-profile URLs not chosen',()=>assert.equal(c('https://x.com/one/status/123 https://youtube.com/watch?v=123'),null));
