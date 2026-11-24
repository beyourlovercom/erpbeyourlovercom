import {test} from 'node:test';
import assert from 'node:assert/strict';
import {validShapeRequest,validShapeAssignments} from './shape-contract.mjs';
const p={lines:[{index:0,label:null,kind:'phone_like'},{index:1,label:null,kind:'text'},{index:2,label:null,kind:'postal_like'}],missing:['phone','postal_code','recipient_name']};
test('request contains exact enums only',()=>{
 assert.equal(validShapeRequest(p),true);
 for(const bad of [{...p,raw:'secret'},{...p,lines:[{index:0,label:null,kind:'text',value:'secret'}]},{...p,lines:[{index:7,label:null,kind:'text'}]},{...p,missing:['recipient_customs_id']}])assert.equal(validShapeRequest(bad),false);
});
test('model cannot invent values or assign ambiguous raw text',()=>{
 assert.equal(validShapeAssignments(p,[{index:0,field:'phone'},{index:2,field:'postal_code'}]),true);
 for(const bad of [[{index:0,field:'phone',value:'x'}],[{index:1,field:'recipient_name'}],[{index:9,field:'phone'}],[{index:0,field:'phone'},{index:0,field:'postal_code'}]])assert.equal(validShapeAssignments(p,bad),false);
});
test('repeated candidate shapes must remain manual',()=>{
 const q={...p,lines:[...p.lines,{index:3,label:null,kind:'phone_like'}]};
 assert.equal(validShapeAssignments(q,[{index:0,field:'phone'}]),false);
});
