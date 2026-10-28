'use strict';

const assert=require('node:assert/strict');
require('../assets/graph_helpers.js');
const graph=globalThis.NetPulseGraph;

const sources=[
  {id:'31',name:'Core ↔ Edge',device_name:'Core-SW',ip:'10.20.0.1',ifname:'xe-0/0/0',alias:'WAN uplink'},
  {id:'p:8:4',name:'Access-SW / ether4',device_name:'Access-SW',ip:'10.20.0.8',ifname:'ether4',alias:'Office floor'}
];

assert.deepEqual(graph.ranges,['hour','day','week','month','year']);
assert.equal(graph.sourceMatches(sources[0],'10.20.0.1'),true);
assert.equal(graph.sourceMatches(sources[0],'WAN uplink'),true);
assert.equal(graph.sourceMatches(sources[1],'access-sw ether4 office'),true);
assert.equal(graph.sourceMatches(sources[1],'10.20.0.1'),false);
assert.equal(graph.sourceMeta(sources[0]),'Core-SW · 10.20.0.1 · xe-0/0/0');

assert.deepEqual(graph.selectMatching(sources,'access-sw'),['p:8:4']);
assert.deepEqual(graph.selectMatching(sources,''),['31','p:8:4']);
assert.deepEqual(graph.toggleSelection(['31'],'p:8:4',true),['31','p:8:4']);
assert.deepEqual(graph.toggleSelection(['31','p:8:4'],'31',false),['p:8:4']);
assert.deepEqual(graph.clearSelection(),[]);
assert.deepEqual(graph.selectionForSources([],sources),['31']);
assert.deepEqual(graph.selectionForSources(['removed'],sources),['31']);
assert.deepEqual(graph.selectionForSources(['p:8:4'],sources),['p:8:4']);
assert.deepEqual(graph.selectionForSources(['removed'],[]),[]);
const mx80Sources=[{id:'p:9:2',name:'MX80 / ge-0/0/0',mx80_interface:true},...sources];
assert.deepEqual(graph.selectionForSources([],mx80Sources),['31']);
assert.deepEqual(graph.selectionForSources([],mx80Sources.slice(0,1)),[]);
const mx80Link={id:'22',name:'MX80 ↔ Access',mx80_interface:true,mx80_selection_id:'p:9:2'};
const mx80Port={id:'p:9:2',name:'MX80 / ge-0/0/0',mx80_interface:true,mx80_selection_id:'p:9:2'};
assert.equal(graph.sourceSelectionId(mx80Link),'p:9:2');
assert.deepEqual(graph.pickerOptions([mx80Link,mx80Port],'MX80'),[
  {key:'p:9:2',graphSource:mx80Link,labelSource:mx80Port}
]);
assert.deepEqual(graph.pickerOptions([mx80Link,mx80Port],'uplink'),[]);

const summary={current:{rx:12,tx:13},average:{rx:8,tx:9},maximum:{rx:20,tx:21}};
assert.deepEqual(graph.summaryEntries(summary),[
  ['Latest Rx',12],['Latest Tx',13],['Avg Rx',8],['Avg Tx',9],['Max Rx',20],['Max Tx',21]
]);
assert.deepEqual(graph.summaryEntries(null),[]);
assert.equal(graph.formatRate(950),'950 bps');
assert.equal(graph.formatRate(1250),'1.3 kbps');
assert.equal(graph.formatRate(2500000),'2.50 Mbps');
assert.equal(graph.formatRate(3500000000),'3.50 Gbps');
assert.equal(graph.formatRate(null),'–');
assert.equal(graph.hasHistory([]),false);
assert.equal(graph.hasHistory([{t:1,rx:1,tx:1}]),false);
assert.equal(graph.hasHistory([{t:1,rx:1,tx:1},{t:2,rx:2,tx:2}]),true);
assert.equal(graph.canFavorite('admin'),true);
assert.equal(graph.canFavorite('administrator'),true);
assert.equal(graph.canFavorite('user'),false);

console.log('Graphs source search, selection, ranges, summaries, favorites, and empty-history tests passed.');
